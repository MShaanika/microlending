<?php

namespace App\Models;

use App\Core\Model;

class HrmWarning extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = w.employee_id
        LEFT JOIN users u ON u.id = w.warning_by
        LEFT JOIN hrm_warning_types t ON t.id = w.warning_type_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        u.name AS warning_by_name, t.name AS warning_type_name
    ";

    public function allWarnings(array $filters = []): array
    {
        $sql = "SELECT w.*, " . self::LOOKUP_COLUMNS . " FROM hrm_warnings w " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'w.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'w.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY w.warning_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT w.*, " . self::LOOKUP_COLUMNS . " FROM hrm_warnings w " . self::LOOKUP_JOINS . " WHERE w.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_warnings', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_warnings', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_warnings WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
