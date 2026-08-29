<?php

namespace App\Models;

use App\Core\Model;

class SlaInstance extends Model
{
    public function create(array $data): int
    {
        return $this->insert('sla_instances', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT i.*, p.policy_name, p.duration_minutes, p.at_risk_threshold_percent, u.name AS owner_name
             FROM sla_instances i
             JOIN sla_policies p ON p.id = i.policy_id
             LEFT JOIN users u ON u.id = i.owner_user_id
             WHERE i.id = ?",
            [$id]
        );
    }

    /** The one open (non-terminal) instance for a resource, if any -- mirrors ApprovalRequest::findPendingByResource()'s shape. */
    public function findOpenByResource(string $resourceType, int $resourceId): ?array
    {
        return $this->one(
            "SELECT i.*, p.duration_minutes, p.at_risk_threshold_percent FROM sla_instances i
             JOIN sla_policies p ON p.id = i.policy_id
             WHERE i.resource_type = ? AND i.resource_id = ? AND i.status NOT IN ('COMPLETED', 'CANCELLED')
             ORDER BY i.id DESC LIMIT 1",
            [$resourceType, $resourceId]
        );
    }

    public function updateStatus(int $id, string $status): void
    {
        $data = ['status' => $status];
        if (in_array($status, ['COMPLETED', 'CANCELLED'], true)) {
            $data['completed_at'] = date('Y-m-d H:i:s');
        }
        $this->update('sla_instances', $data, 'id', $id);
    }

    public function pause(int $id): void
    {
        $this->update('sla_instances', ['status' => 'PAUSED', 'paused_at' => date('Y-m-d H:i:s')], 'id', $id);
    }

    /** Extends due_at forward by however long the instance was paused, so pause time never counts against the SLA clock. */
    public function resume(int $id): void
    {
        $row = $this->one('SELECT paused_at, due_at FROM sla_instances WHERE id = ?', [$id]);
        if (!$row || !$row['paused_at']) {
            return;
        }
        $pausedMinutes = (int) round((strtotime('now') - strtotime($row['paused_at'])) / 60);
        $newDueAt = date('Y-m-d H:i:s', strtotime($row['due_at']) + $pausedMinutes * 60);

        $this->query(
            "UPDATE sla_instances SET status = 'ON_TRACK', paused_at = NULL, paused_minutes_total = paused_minutes_total + ?, due_at = ? WHERE id = ?",
            [$pausedMinutes, $newDueAt, $id]
        );
    }

    public function bumpEscalationLevel(int $id): void
    {
        $this->query('UPDATE sla_instances SET escalation_level = escalation_level + 1 WHERE id = ?', [$id]);
    }

    /** Every non-terminal instance -- what bin/evaluate_sla.php sweeps each run. */
    public function activeInstances(): array
    {
        return $this->all(
            "SELECT i.*, p.duration_minutes, p.at_risk_threshold_percent FROM sla_instances i
             JOIN sla_policies p ON p.id = i.policy_id
             WHERE i.status NOT IN ('COMPLETED', 'CANCELLED', 'PAUSED')"
        );
    }

    public function logEvent(int $instanceId, string $eventType, ?int $thresholdPercent = null, ?string $notes = null): void
    {
        $this->insert('sla_events', [
            'sla_instance_id' => $instanceId,
            'event_type' => $eventType,
            'threshold_percent' => $thresholdPercent,
            'notes' => $notes,
        ]);
    }

    public function alreadyEscalatedAt(int $instanceId, int $thresholdPercent): bool
    {
        return (bool) $this->scalar(
            "SELECT COUNT(*) FROM sla_events WHERE sla_instance_id = ? AND event_type = 'ESCALATED' AND threshold_percent = ?",
            [$instanceId, $thresholdPercent]
        );
    }

    public function timeline(int $instanceId): array
    {
        return $this->all('SELECT * FROM sla_events WHERE sla_instance_id = ? ORDER BY created_at ASC', [$instanceId]);
    }

    public function findActivePolicy(string $policyKey): ?array
    {
        return $this->one('SELECT * FROM sla_policies WHERE policy_key = ? AND is_active = 1', [$policyKey]);
    }

    public function escalationRulesFor(int $policyId): array
    {
        return $this->all('SELECT * FROM sla_escalations WHERE policy_id = ? AND is_active = 1 ORDER BY threshold_percent ASC', [$policyId]);
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
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM sla_instances i" . $whereSql, $params);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT i.*, p.policy_name, u.name AS owner_name FROM sla_instances i
             JOIN sla_policies p ON p.id = i.policy_id
             LEFT JOIN users u ON u.id = i.owner_user_id"
            . $whereSql . " ORDER BY i.due_at ASC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    // -- Policy administration (list/create/toggle) --

    public function allPolicies(): array
    {
        return $this->all('SELECT p.*, u.name AS updated_by_name FROM sla_policies p LEFT JOIN users u ON u.id = p.updated_by ORDER BY p.policy_name');
    }

    public function findPolicy(int $id): ?array
    {
        return $this->one('SELECT * FROM sla_policies WHERE id = ?', [$id]);
    }

    public function createPolicy(array $data): int
    {
        return $this->insert('sla_policies', $data);
    }

    public function updatePolicy(int $id, array $data): void
    {
        $this->update('sla_policies', $data, 'id', $id);
    }
}
