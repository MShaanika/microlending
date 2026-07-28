<?php

namespace App\Models;

use App\Core\Model;

class HrmTransfer extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = t.employee_id
        LEFT JOIN branches fb ON fb.id = t.from_branch_id
        LEFT JOIN hrm_departments fd ON fd.id = t.from_department_id
        LEFT JOIN hrm_designations fg ON fg.id = t.from_designation_id
        LEFT JOIN branches tb ON tb.id = t.to_branch_id
        LEFT JOIN hrm_departments td ON td.id = t.to_department_id
        LEFT JOIN hrm_designations tg ON tg.id = t.to_designation_id
        LEFT JOIN users u ON u.id = t.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        fb.branch_name AS from_branch_name, fd.department_name AS from_department_name, fg.designation_name AS from_designation_name,
        tb.branch_name AS to_branch_name, td.department_name AS to_department_name, tg.designation_name AS to_designation_name,
        u.name AS approved_by_name
    ";

    public function allTransfers(array $filters = []): array
    {
        $sql = "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM hrm_transfers t " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 't.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.effective_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM hrm_transfers t " . self::LOOKUP_JOINS . " WHERE t.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_transfers', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_transfers', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_transfers WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
