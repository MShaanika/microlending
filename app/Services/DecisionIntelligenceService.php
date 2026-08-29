<?php

namespace App\Services;

use App\Core\Database;

/**
 * Decision Intelligence (Part 7-9 of the enterprise architecture) --
 * read-only aggregation over what every other framework already
 * captured: exceptions' root_cause (Exception Centre, Phase 3),
 * data_quality_issues (Phase 4), system_errors (Phase 5), and SLA
 * breaches (Phase 3). Nothing here writes anything or invents a
 * business rule/threshold -- it turns operational history already on
 * record into "what keeps going wrong, and where" for a human to act
 * on. The recurrence/window cutoffs below are reporting defaults, not
 * financial or approval policy, so they're fixed in code rather than
 * routed through system_settings like error_display_mode was.
 */
class DecisionIntelligenceService
{
    /**
     * Per-module composite risk score: open exceptions (weighted by
     * severity) + open data quality issues + error occurrences +
     * breached SLA instances, all within the window. A simple additive
     * score, not a statistically-derived one -- good enough to rank
     * "where to look first," not to justify on its own.
     */
    public static function hotspotsByModule(int $days = 30): array
    {
        $db = Database::connection();
        $scores = [];

        $severityWeight = "CASE severity WHEN 'Critical' THEN 4 WHEN 'High' THEN 3 WHEN 'Medium' THEN 2 ELSE 1 END";

        // Window cutoffs are computed by MySQL itself (NOW() - INTERVAL),
        // not a PHP-computed date string -- production's MySQL server
        // clock and PHP's configured timezone (Africa/Windhoek) don't
        // agree, so binding a PHP-side "N days ago" against a
        // MySQL-clock timestamp column is silently off by hours.
        $rows = $db->prepare(
            "SELECT module,
                    COUNT(*) AS exception_count,
                    SUM($severityWeight) AS exception_weight
             FROM exceptions WHERE detected_at >= NOW() - INTERVAL ? DAY GROUP BY module"
        );
        $rows->execute([$days]);
        foreach ($rows->fetchAll() as $r) {
            self::bump($scores, $r['module'], 'exceptions', (int) $r['exception_count'], (int) $r['exception_weight']);
        }

        $rows = $db->query(
            "SELECT r.module, COUNT(*) AS issue_count
             FROM data_quality_issues i
             INNER JOIN data_quality_rules r ON r.id = i.rule_id
             WHERE i.status IN ('OPEN', 'CONFIRMED', 'REVIEWING')
             GROUP BY r.module"
        );
        foreach ($rows->fetchAll() as $r) {
            self::bump($scores, $r['module'], 'data_quality_issues', (int) $r['issue_count'], (int) $r['issue_count'] * 2);
        }

        $stmt = $db->prepare(
            "SELECT module, COUNT(*) AS error_count, SUM(occurrence_count) AS occurrences
             FROM system_errors WHERE last_seen_at >= NOW() - INTERVAL ? DAY AND status NOT IN ('RESOLVED', 'IGNORED') AND module IS NOT NULL
             GROUP BY module"
        );
        $stmt->execute([$days]);
        foreach ($stmt->fetchAll() as $r) {
            self::bump($scores, $r['module'], 'system_errors', (int) $r['error_count'], (int) $r['occurrences']);
        }

        $stmt = $db->prepare(
            "SELECT p.module, COUNT(*) AS breach_count
             FROM sla_instances si
             INNER JOIN sla_policies p ON p.id = si.policy_id
             WHERE si.status = 'BREACHED' AND si.created_at >= NOW() - INTERVAL ? DAY
             GROUP BY p.module"
        );
        $stmt->execute([$days]);
        foreach ($stmt->fetchAll() as $r) {
            self::bump($scores, $r['module'], 'sla_breaches', (int) $r['breach_count'], (int) $r['breach_count'] * 3);
        }

        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $scores;
    }

    private static function bump(array &$scores, ?string $module, string $sourceKey, int $count, int $weight): void
    {
        $module = $module ?: 'Unclassified';
        if (!isset($scores[$module])) {
            $scores[$module] = ['module' => $module, 'score' => 0, 'exceptions' => 0, 'data_quality_issues' => 0, 'system_errors' => 0, 'sla_breaches' => 0];
        }
        $scores[$module]['score'] += $weight;
        $scores[$module][$sourceKey] += $count;
    }

    /**
     * A module+category+exception_type combination recurring at least
     * $minOccurrences times in the window -- a genuine "this keeps
     * happening" signal, distinct from a one-off.
     */
    public static function recurringPatterns(int $days = 90, int $minOccurrences = 3): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT module, category, exception_type, COUNT(*) AS occurrences,
                    MAX(detected_at) AS last_seen,
                    SUBSTRING_INDEX(GROUP_CONCAT(root_cause ORDER BY detected_at DESC SEPARATOR '|~|'), '|~|', 1) AS latest_root_cause
             FROM exceptions
             WHERE detected_at >= NOW() - INTERVAL ? DAY
             GROUP BY module, category, exception_type
             HAVING COUNT(*) >= ?
             ORDER BY occurrences DESC
             LIMIT 20"
        );
        $stmt->execute([$days, $minOccurrences]);
        return $stmt->fetchAll();
    }

    /** Daily new-exception counts for a trend chart. */
    public static function exceptionTrend(int $days = 30): array
    {
        $db = Database::connection();

        // Day buckets are anchored to MySQL's own CURDATE(), not PHP's
        // date() -- the data being bucketed (detected_at) is timestamped
        // on MySQL's clock, so "today" for bucketing purposes has to
        // agree with that clock too (see hotspotsByModule()). A bare
        // date string carries no time-of-day, so the day-arithmetic
        // below is safe from the PHP/MySQL timezone mismatch either way.
        $today = $db->query('SELECT CURDATE()')->fetchColumn();

        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("$today -{$i} days"));
            $rows[$day] = ['label' => date('d M', strtotime($day)), 'date' => $day, 'count' => 0];
        }

        $stmt = $db->prepare(
            "SELECT DATE(detected_at) AS d, COUNT(*) AS count FROM exceptions WHERE detected_at >= CURDATE() - INTERVAL ? DAY GROUP BY DATE(detected_at)"
        );
        $stmt->execute([$days - 1]);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($rows[$r['d']])) {
                $rows[$r['d']]['count'] = (int) $r['count'];
            }
        }
        return array_values($rows);
    }

    /** Average hours from detection to resolution, by severity, for exceptions resolved in the window -- where things get stuck the longest. */
    public static function resolutionMetrics(int $days = 90): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT severity,
                    COUNT(*) AS resolved_count,
                    AVG(TIMESTAMPDIFF(MINUTE, detected_at, resolved_at)) / 60 AS avg_hours
             FROM exceptions
             WHERE status IN ('RESOLVED', 'CLOSED', 'ACCEPTED_RISK') AND resolved_at IS NOT NULL AND resolved_at >= NOW() - INTERVAL ? DAY
             GROUP BY severity
             ORDER BY FIELD(severity, 'Critical', 'High', 'Medium', 'Low')"
        );
        $stmt->execute([$days]);
        return array_map(static function ($r) {
            $r['avg_hours'] = round((float) $r['avg_hours'], 1);
            return $r;
        }, $stmt->fetchAll());
    }

    /** Most recent exceptions with a human-written root cause -- a readable feed, not a fragmented group-by over free text. */
    public static function recentRootCauses(int $limit = 10): array
    {
        $db = Database::connection();
        return $db->query(
            "SELECT id, module, category, exception_type, severity, root_cause, resolved_at
             FROM exceptions WHERE root_cause IS NOT NULL AND root_cause != ''
             ORDER BY resolved_at DESC LIMIT " . max(1, $limit)
        )->fetchAll();
    }
}
