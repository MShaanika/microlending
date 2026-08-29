<?php

namespace App\Services;

use App\Core\ClientIp;
use App\Core\Database;
use App\Core\SecurityEvent;
use App\Models\SecurityBlockedSource;
use App\Models\SecurityIncident;
use App\Models\SecurityRule;

/**
 * Synchronous, inline rule evaluation -- runs immediately after a
 * SecurityEvent::record() call, same cost profile as the app's one
 * existing rate-limiter (IntakeSource::recentSubmissionCount(): one
 * indexed COUNT(*) query per rule). No cron sweep for Phase 1's rules:
 * login/permission-denial volume is low relative to normal traffic, and
 * the block response has to run synchronously to actually stop the next
 * attempt.
 *
 * Callers MUST wrap evaluate() in a try/catch (see Auth::attempt()) -- a
 * bug here must never be able to break login, matching Audit::log()'s
 * "keep the app alive" philosophy.
 */
class SecurityRuleEngine
{
    /**
     * @param string|null $account Normalized (trim+lower) login string, if this event is login-related.
     * @param bool $exemptAccount True when $account belongs to a Super Admin/Admin/bypass-flagged
     *        user (see Auth::attempt()) -- detection, incidents, and notifications still fire, but
     *        a 'lock_account' response is never applied against it (that block would never actually
     *        be enforced anyway, since Auth::attempt() skips the block check for exempt users; leaving
     *        it non-blocking prevents a misleading "Active" block appearing on the Blocked Sources page).
     */
    public static function evaluate(string $eventType, ?string $ip = null, ?string $account = null, bool $exemptAccount = false): void
    {
        $ip = $ip ?? ClientIp::resolve();
        $rules = new SecurityRule();
        $incidents = new SecurityIncident();
        $blocks = new SecurityBlockedSource();
        $db = Database::connection();

        foreach ($rules->activeForEventType($eventType) as $rule) {
            $scopeValue = self::scopeValue($rule['scope'], $ip, $account);
            if ($scopeValue === null) {
                continue; // e.g. an 'account' or 'ip_distinct_accounts' rule with no login string available
            }

            $count = self::countInWindow($db, $rule, $ip, $account);
            if ($count < (int) $rule['threshold_count']) {
                continue;
            }

            $incidentKey = $rule['rule_key'] . '|' . $scopeValue;
            // Computed by MySQL itself (NOW()/INTERVAL), not PHP's
            // time() -- this window is compared against security_events
            // rows timestamped on MySQL's own clock, which disagrees
            // with PHP's configured timezone on production.
            $windowMinutes = (int) $rule['window_minutes'];
            $clock = $db->query("SELECT NOW() AS now_val, NOW() - INTERVAL {$windowMinutes} MINUTE AS window_start")->fetch();
            $now = $clock['now_val'];
            $windowStart = $clock['window_start'];

            $result = $incidents->createOrAppend([
                'incident_key' => $incidentKey,
                'title' => $rule['rule_name'] . ': ' . $scopeValue,
                'severity' => $rule['severity'],
                'source_ip' => $rule['scope'] === 'account' ? null : $ip,
                'source_login' => $rule['scope'] === 'account' ? $scopeValue : $account,
                'rule_id' => $rule['id'],
                'event_count' => $count,
                'first_event_at' => $windowStart,
                'last_event_at' => $now,
            ]);
            $incidentId = $result['id'];

            // Link this window's previously-unlinked events to the incident so
            // its investigation timeline shows everything that led up to it,
            // not just the single event that tripped the threshold.
            self::linkEventsToIncident($db, $rule, $ip, $account, $incidentId, $windowStart);

            $rules->markTriggered((int) $rule['id']);

            // Blocking response applies on every re-trigger (e.g. to extend
            // an expiring block under sustained attack), but the admin
            // notification is throttled to once per incident -- otherwise
            // every failed login past the threshold would send another
            // email, exactly the "hundreds of duplicate notifications" the
            // spec warns against.
            $skipResponse = $exemptAccount && $rule['response_action'] === 'lock_account';
            if ($rule['response_action'] !== 'none' && !$skipResponse) {
                self::applyResponse($blocks, $rule, $scopeValue, $incidentId);
            }
            if ($result['is_new']) {
                SecurityNotificationService::notifyIncident($incidentId, $rule['severity']);
            }
        }
    }

    private static function scopeValue(string $scope, string $ip, ?string $account): ?string
    {
        return match ($scope) {
            'ip', 'ip_distinct_accounts' => $ip,
            'account' => $account,
            default => null,
        };
    }

    private static function countInWindow(\PDO $db, array $rule, string $ip, ?string $account): int
    {
        $windowSql = 'created_at >= NOW() - INTERVAL ? MINUTE';
        $window = (int) $rule['window_minutes'];

        return match ($rule['scope']) {
            'ip' => (int) self::scalar($db, "SELECT COUNT(*) FROM security_events WHERE event_type = ? AND ip_address = ? AND $windowSql", [$rule['event_type'], $ip, $window]),
            'account' => $account === null ? 0 : (int) self::scalar($db, "SELECT COUNT(*) FROM security_events WHERE event_type = ? AND attempted_login = ? AND $windowSql", [$rule['event_type'], $account, $window]),
            'ip_distinct_accounts' => (int) self::scalar($db, "SELECT COUNT(DISTINCT attempted_login) FROM security_events WHERE event_type = ? AND ip_address = ? AND attempted_login IS NOT NULL AND $windowSql", [$rule['event_type'], $ip, $window]),
            default => 0,
        };
    }

    private static function linkEventsToIncident(\PDO $db, array $rule, string $ip, ?string $account, int $incidentId, string $windowStart): void
    {
        $sql = match ($rule['scope']) {
            'ip', 'ip_distinct_accounts' => "UPDATE security_events SET incident_id = ?, rule_id = ? WHERE event_type = ? AND ip_address = ? AND created_at >= ? AND incident_id IS NULL",
            'account' => "UPDATE security_events SET incident_id = ?, rule_id = ? WHERE event_type = ? AND attempted_login = ? AND created_at >= ? AND incident_id IS NULL",
            default => null,
        };
        if ($sql === null) {
            return;
        }
        $scopeParam = $rule['scope'] === 'account' ? $account : $ip;
        $db->prepare($sql)->execute([$incidentId, $rule['id'], $rule['event_type'], $scopeParam, $windowStart]);
    }

    private static function applyResponse(SecurityBlockedSource $blocks, array $rule, string $scopeValue, int $incidentId): void
    {
        $blockType = $rule['response_action'] === 'lock_account' ? 'account' : 'ip';
        if ($blocks->activeBlock($blockType, $scopeValue)) {
            return; // already blocked -- don't fight the UNIQUE constraint over a no-op
        }

        $durationMinutes = $rule['response_duration_minutes'] !== null ? (int) $rule['response_duration_minutes'] : null;
        try {
            $blocks->create([
                'block_type' => $blockType,
                'block_value' => $scopeValue,
                'reason' => 'Automatic: ' . $rule['rule_name'],
                'rule_id' => $rule['id'],
                'incident_id' => $incidentId,
                'blocked_by' => null, // NULL = system-applied, not an admin action
            ], $durationMinutes);
        } catch (\Throwable $e) {
            // A concurrent request won the race to insert the same block --
            // harmless, the block exists either way.
            error_log('SecurityRuleEngine::applyResponse: ' . $e->getMessage());
        }
    }

    private static function scalar(\PDO $db, string $sql, array $params): mixed
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
