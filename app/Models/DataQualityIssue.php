<?php

namespace App\Models;

use App\Core\Model;

class DataQualityIssue extends Model
{
    /** Re-opens an existing (or creates a new) issue for this exact rule+resource -- re-scanning never creates duplicates for a condition still failing. */
    public function upsert(int $ruleId, string $resourceType, int $resourceId, string $description, ?string $correlationId): array
    {
        $existing = $this->one(
            "SELECT * FROM data_quality_issues WHERE rule_id = ? AND resource_type = ? AND resource_id = ?",
            [$ruleId, $resourceType, $resourceId]
        );

        if ($existing) {
            $this->update('data_quality_issues', [
                'description' => $description,
                'last_seen_at' => date('Y-m-d H:i:s'),
                // A previously false-positived/resolved issue that's
                // failing again on rescan reopens -- the underlying
                // condition is back, whatever was said about it before.
                'status' => in_array($existing['status'], ['RESOLVED', 'FALSE_POSITIVE'], true) ? 'OPEN' : $existing['status'],
            ], 'id', (int) $existing['id']);
            return ['id' => (int) $existing['id'], 'is_new' => false];
        }

        $id = $this->insert('data_quality_issues', [
            'rule_id' => $ruleId,
            'correlation_id' => $correlationId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'description' => $description,
            'status' => 'OPEN',
        ]);
        return ['id' => $id, 'is_new' => true];
    }

    /** Every OPEN/REVIEWING/CONFIRMED issue for this rule NOT in $stillFailingIds -- the condition no longer holds, so the issue auto-resolves rather than requiring someone to notice and close it by hand. */
    public function autoResolveNoLongerFailing(int $ruleId, array $stillFailingIds): int
    {
        if (empty($stillFailingIds)) {
            $stmt = $this->query(
                "UPDATE data_quality_issues SET status = 'RESOLVED', resolved_at = NOW(), resolution_notes = 'Auto-resolved: condition no longer detected on rescan.'
                 WHERE rule_id = ? AND status IN ('OPEN', 'REVIEWING', 'CONFIRMED')",
                [$ruleId]
            );
            return $stmt->rowCount();
        }
        $placeholders = implode(',', array_fill(0, count($stillFailingIds), '?'));
        $stmt = $this->query(
            "UPDATE data_quality_issues SET status = 'RESOLVED', resolved_at = NOW(), resolution_notes = 'Auto-resolved: condition no longer detected on rescan.'
             WHERE rule_id = ? AND status IN ('OPEN', 'REVIEWING', 'CONFIRMED') AND resource_id NOT IN ($placeholders)",
            array_merge([$ruleId], $stillFailingIds)
        );
        return $stmt->rowCount();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT i.*, r.rule_name, r.rule_key, r.dimension, r.severity, r.remediation_guidance, ru.name AS resolved_by_name
             FROM data_quality_issues i
             JOIN data_quality_rules r ON r.id = i.rule_id
             LEFT JOIN users ru ON ru.id = i.resolved_by
             WHERE i.id = ?",
            [$id]
        );
    }

    public function markStatus(int $id, string $status, ?int $resolvedBy = null, ?string $notes = null): void
    {
        $data = ['status' => $status];
        if (in_array($status, ['RESOLVED', 'FALSE_POSITIVE'], true)) {
            $data['resolved_at'] = date('Y-m-d H:i:s');
            $data['resolved_by'] = $resolvedBy;
            $data['resolution_notes'] = $notes;
        }
        $this->update('data_quality_issues', $data, 'id', $id);
    }

    public function linkException(int $id, int $exceptionId): void
    {
        $this->update('data_quality_issues', ['exception_id' => $exceptionId], 'id', $id);
    }

    public function counts(): array
    {
        return [
            'open' => (int) $this->scalar("SELECT COUNT(*) FROM data_quality_issues WHERE status IN ('OPEN','REVIEWING','CONFIRMED')"),
            'critical' => (int) $this->scalar("SELECT COUNT(*) FROM data_quality_issues i JOIN data_quality_rules r ON r.id=i.rule_id WHERE r.severity='Critical' AND i.status IN ('OPEN','REVIEWING','CONFIRMED')"),
            'resolved_total' => (int) $this->scalar("SELECT COUNT(*) FROM data_quality_issues WHERE status = 'RESOLVED'"),
            'false_positive_total' => (int) $this->scalar("SELECT COUNT(*) FROM data_quality_issues WHERE status = 'FALSE_POSITIVE'"),
        ];
    }

    /** Per-dimension open-issue breakdown -- what the dashboard's quality score/trend reads. */
    public function openCountsByDimension(): array
    {
        return $this->all(
            "SELECT r.dimension, COUNT(*) AS total FROM data_quality_issues i
             JOIN data_quality_rules r ON r.id = i.rule_id
             WHERE i.status IN ('OPEN','REVIEWING','CONFIRMED') GROUP BY r.dimension"
        );
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'i.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['dimension'])) {
            $where[] = 'r.dimension = ?';
            $params[] = $filters['dimension'];
        }
        if (!empty($filters['rule_id'])) {
            $where[] = 'i.rule_id = ?';
            $params[] = $filters['rule_id'];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM data_quality_issues i JOIN data_quality_rules r ON r.id = i.rule_id" . $whereSql,
            $params
        );
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT i.*, r.rule_name, r.dimension, r.severity FROM data_quality_issues i
             JOIN data_quality_rules r ON r.id = i.rule_id"
            . $whereSql . " ORDER BY i.detected_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    // -- Rule administration --

    public function allRules(): array
    {
        return $this->all("SELECT r.*, u.name AS updated_by_name FROM data_quality_rules r LEFT JOIN users u ON u.id = r.updated_by ORDER BY r.rule_name");
    }

    public function findRule(int $id): ?array
    {
        return $this->one('SELECT * FROM data_quality_rules WHERE id = ?', [$id]);
    }

    public function activeRules(): array
    {
        return $this->all('SELECT * FROM data_quality_rules WHERE is_active = 1');
    }

    public function updateRule(int $id, array $data): void
    {
        $this->update('data_quality_rules', $data, 'id', $id);
    }

    public function markRuleRun(int $id): void
    {
        $this->update('data_quality_rules', ['last_run_at' => date('Y-m-d H:i:s')], 'id', $id);
    }
}
