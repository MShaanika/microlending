<?php

namespace App\Models;

use App\Core\Model;

class SystemError extends Model
{
    public function findByFingerprint(string $fingerprint): ?array
    {
        return $this->one('SELECT * FROM system_errors WHERE fingerprint = ?', [$fingerprint]);
    }

    public function create(array $data): int
    {
        return $this->insert('system_errors', $data);
    }

    public function bumpOccurrence(int $id, string $correlationId): void
    {
        $this->query(
            "UPDATE system_errors SET occurrence_count = occurrence_count + 1, last_seen_at = NOW(), correlation_id = ?,
             status = IF(status = 'RESOLVED', 'REOPENED', status)
             WHERE id = ?",
            [$correlationId, $id]
        );
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT e.*, u.name AS resolved_by_name FROM system_errors e LEFT JOIN users u ON u.id = e.resolved_by WHERE e.id = ?",
            [$id]
        );
    }

    public function updateStatus(int $id, string $status, ?int $resolvedBy = null): void
    {
        $data = ['status' => $status];
        if ($status === 'RESOLVED') {
            $data['resolved_at'] = date('Y-m-d H:i:s');
            $data['resolved_by'] = $resolvedBy;
        }
        $this->update('system_errors', $data, 'id', $id);
    }

    public function linkException(int $id, int $exceptionId): void
    {
        $this->update('system_errors', ['exception_id' => $exceptionId], 'id', $id);
    }

    public function counts(): array
    {
        return [
            'new' => (int) $this->scalar("SELECT COUNT(*) FROM system_errors WHERE status = 'NEW'"),
            'critical' => (int) $this->scalar("SELECT COUNT(*) FROM system_errors WHERE severity = 'Critical' AND status NOT IN ('RESOLVED','IGNORED')"),
            'unresolved' => (int) $this->scalar("SELECT COUNT(*) FROM system_errors WHERE status NOT IN ('RESOLVED','IGNORED')"),
            'today' => (int) $this->scalar("SELECT COUNT(*) FROM system_errors WHERE DATE(last_seen_at) = CURDATE()"),
        ];
    }

    public function mostFrequent(int $limit = 5): array
    {
        return $this->all("SELECT id, safe_message, exception_class, occurrence_count FROM system_errors WHERE status NOT IN ('RESOLVED','IGNORED') ORDER BY occurrence_count DESC LIMIT " . max(1, $limit));
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['module'])) {
            $where[] = 'module = ?';
            $params[] = $filters['module'];
        }
        if (!empty($filters['correlation_id'])) {
            $where[] = 'correlation_id = ?';
            $params[] = $filters['correlation_id'];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM system_errors" . $whereSql, $params);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT * FROM system_errors" . $whereSql . " ORDER BY last_seen_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function distinctModules(): array
    {
        return $this->query('SELECT DISTINCT module FROM system_errors WHERE module IS NOT NULL ORDER BY module')->fetchAll(\PDO::FETCH_COLUMN);
    }
}
