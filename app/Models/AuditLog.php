<?php

namespace App\Models;

use App\Core\Model;

class AuditLog extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN users u ON u.id = a.user_id";
    private const LOOKUP_COLUMNS = "u.name AS user_name";

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['module_name'])) {
            $where[] = 'a.module_name = ?';
            $params[] = $filters['module_name'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'a.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'a.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = 'a.description LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_logs a " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM audit_logs a " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY a.created_at DESC, a.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function distinctModules(): array
    {
        return $this->query("SELECT DISTINCT module_name FROM audit_logs ORDER BY module_name")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function distinctActions(): array
    {
        return $this->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(\PDO::FETCH_COLUMN);
    }
}
