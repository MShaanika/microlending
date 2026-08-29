<?php

namespace App\Models;

use App\Core\Model;

/** Named ExceptionRecord, not Exception -- App\Models\Exception would collide in spirit (and easily in a stray `use` statement) with PHP's own built-in \Exception class. */
class ExceptionRecord extends Model
{
    public function create(array $data): int
    {
        return $this->insert('exceptions', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT e.*, u.name AS owner_name, ru.name AS resolved_by_name
             FROM exceptions e
             LEFT JOIN users u ON u.id = e.owner_user_id
             LEFT JOIN users ru ON ru.id = e.resolved_by
             WHERE e.id = ?",
            [$id]
        );
    }

    public function assign(int $id, int $ownerUserId): void
    {
        $this->update('exceptions', ['owner_user_id' => $ownerUserId, 'status' => 'ASSIGNED'], 'id', $id);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->update('exceptions', ['status' => $status], 'id', $id);
    }

    public function resolve(int $id, string $status, string $resolution, ?string $rootCause, int $resolvedBy): void
    {
        $this->update('exceptions', [
            'status' => $status,
            'resolution' => $resolution,
            'root_cause' => $rootCause,
            'resolved_by' => $resolvedBy,
            'resolved_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }

    public function reopen(int $id): void
    {
        $this->query(
            "UPDATE exceptions SET status = 'OPEN', reopened_count = reopened_count + 1, resolved_at = NULL, resolved_by = NULL WHERE id = ?",
            [$id]
        );
    }

    public function addNote(int $id, ?int $authorUserId, string $note): void
    {
        $this->insert('exception_notes', ['exception_id' => $id, 'author_user_id' => $authorUserId, 'note' => $note]);
    }

    public function notes(int $id): array
    {
        return $this->all(
            "SELECT n.*, u.name AS author_name FROM exception_notes n LEFT JOIN users u ON u.id = n.author_user_id WHERE n.exception_id = ? ORDER BY n.created_at ASC",
            [$id]
        );
    }

    /** Dashboard counts (Part 25). */
    public function counts(): array
    {
        return [
            'open' => (int) $this->scalar("SELECT COUNT(*) FROM exceptions WHERE status NOT IN ('RESOLVED','ACCEPTED_RISK','CLOSED')"),
            'critical' => (int) $this->scalar("SELECT COUNT(*) FROM exceptions WHERE severity = 'Critical' AND status NOT IN ('RESOLVED','ACCEPTED_RISK','CLOSED')"),
            'high' => (int) $this->scalar("SELECT COUNT(*) FROM exceptions WHERE severity = 'High' AND status NOT IN ('RESOLVED','ACCEPTED_RISK','CLOSED')"),
            'unassigned' => (int) $this->scalar("SELECT COUNT(*) FROM exceptions WHERE owner_user_id IS NULL AND status NOT IN ('RESOLVED','ACCEPTED_RISK','CLOSED')"),
            'sla_breached' => (int) $this->scalar("SELECT COUNT(*) FROM exceptions WHERE due_at IS NOT NULL AND due_at < NOW() AND status NOT IN ('RESOLVED','ACCEPTED_RISK','CLOSED')"),
            'resolved_today' => (int) $this->scalar("SELECT COUNT(*) FROM exceptions WHERE DATE(resolved_at) = CURDATE()"),
            'reopened' => (int) $this->scalar("SELECT COUNT(*) FROM exceptions WHERE reopened_count > 0 AND status NOT IN ('RESOLVED','ACCEPTED_RISK','CLOSED')"),
        ];
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['module'])) {
            $where[] = 'e.module = ?';
            $params[] = $filters['module'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'e.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'e.severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['owner_user_id'])) {
            $where[] = 'e.owner_user_id = ?';
            $params[] = $filters['owner_user_id'];
        }
        if (!empty($filters['correlation_id'])) {
            $where[] = 'e.correlation_id = ?';
            $params[] = $filters['correlation_id'];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM exceptions e" . $whereSql, $params);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT e.*, u.name AS owner_name FROM exceptions e LEFT JOIN users u ON u.id = e.owner_user_id"
            . $whereSql . " ORDER BY e.severity = 'Critical' DESC, e.detected_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function distinctModules(): array
    {
        return $this->query('SELECT DISTINCT module FROM exceptions ORDER BY module')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function distinctCategories(): array
    {
        return $this->query('SELECT DISTINCT category FROM exceptions ORDER BY category')->fetchAll(\PDO::FETCH_COLUMN);
    }
}
