<?php

namespace App\Models;

use App\Core\Model;

class SecurityBlockedSource extends Model
{
    /** Active, unexpired block matching this type+value, if any -- the actual enforcement check called from Auth::attempt(). */
    public function activeBlock(string $type, string $value): ?array
    {
        return $this->one(
            "SELECT * FROM security_blocked_sources
             WHERE block_type = ? AND block_value = ? AND status = 'Active' AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1",
            [$type, $value]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('security_blocked_sources', $data);
    }

    public function countActive(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM security_blocked_sources WHERE status = 'Active' AND (expires_at IS NULL OR expires_at > NOW())");
    }

    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'b.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['block_type'])) {
            $where[] = 'b.block_type = ?';
            $params[] = $filters['block_type'];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM security_blocked_sources b" . $whereSql, $params);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT b.*, u.name AS blocked_by_name, lu.name AS lifted_by_name
             FROM security_blocked_sources b
             LEFT JOIN users u ON u.id = b.blocked_by
             LEFT JOIN users lu ON lu.id = b.lifted_by"
            . $whereSql . " ORDER BY b.blocked_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM security_blocked_sources WHERE id = ?", [$id]);
    }

    public function lift(int $id, int $userId, string $reason): bool
    {
        return $this->update('security_blocked_sources', [
            'status' => 'Lifted',
            'lifted_by' => $userId,
            'lifted_at' => date('Y-m-d H:i:s'),
            'lift_reason' => $reason,
        ], 'id', $id);
    }
}
