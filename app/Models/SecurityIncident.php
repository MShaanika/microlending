<?php

namespace App\Models;

use App\Core\Model;

class SecurityIncident extends Model
{
    /**
     * Correlation: reuses the existing Open/Investigating incident for this
     * exact incident_key (rule_key . '|' . scope_value) if one exists,
     * otherwise creates a fresh one. One sustained attack stays one
     * incident until a human resolves it, rather than fragmenting across
     * arbitrary time-bucket edges.
     *
     * @return array{id: int, is_new: bool} is_new is what callers use to
     *         throttle notifications -- alert once per incident, not once
     *         per event that re-confirms an already-open one.
     */
    public function createOrAppend(array $data): array
    {
        $existing = $this->one(
            "SELECT id, event_count FROM security_incidents WHERE incident_key = ? AND status IN ('Open','Investigating') LIMIT 1",
            [$data['incident_key']]
        );

        if ($existing) {
            $id = (int) $existing['id'];
            $this->update('security_incidents', [
                'event_count' => (int) $existing['event_count'] + 1,
                'last_event_at' => $data['last_event_at'],
                'severity' => $data['severity'], // a later, higher-severity breach of the same rule escalates the open incident
            ], 'id', $id);
            return ['id' => $id, 'is_new' => false];
        }

        return ['id' => $this->insert('security_incidents', $data), 'is_new' => true];
    }

    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'i.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'i.severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'i.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'i.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM security_incidents i" . $whereSql, $params);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT i.*, u.name AS assigned_to_name, ru.name AS resolved_by_name
             FROM security_incidents i
             LEFT JOIN users u ON u.id = i.assigned_to
             LEFT JOIN users ru ON ru.id = i.resolved_by"
            . $whereSql . " ORDER BY i.last_event_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT i.*, u.name AS assigned_to_name, ru.name AS resolved_by_name
             FROM security_incidents i
             LEFT JOIN users u ON u.id = i.assigned_to
             LEFT JOIN users ru ON ru.id = i.resolved_by
             WHERE i.id = ?",
            [$id]
        );
    }

    public function events(int $incidentId): array
    {
        return $this->all(
            "SELECT e.*, u.name AS user_name FROM security_events e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.incident_id = ? ORDER BY e.created_at ASC",
            [$incidentId]
        );
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('security_incidents', $data, 'id', $id);
    }

    public function counts(): array
    {
        return [
            'open' => (int) $this->scalar("SELECT COUNT(*) FROM security_incidents WHERE status IN ('Open','Investigating')"),
            'critical_open' => (int) $this->scalar("SELECT COUNT(*) FROM security_incidents WHERE status IN ('Open','Investigating') AND severity = 'Critical'"),
            'high_open' => (int) $this->scalar("SELECT COUNT(*) FROM security_incidents WHERE status IN ('Open','Investigating') AND severity IN ('High','Critical')"),
        ];
    }
}
