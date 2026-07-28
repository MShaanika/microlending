<?php

namespace App\Models;

use App\Core\Model;

class HrmEmployee extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN branches b ON b.id = e.branch_id
        LEFT JOIN hrm_departments d ON d.id = e.department_id
        LEFT JOIN hrm_designations g ON g.id = e.designation_id
        LEFT JOIN hrm_shifts s ON s.id = e.shift_id
        LEFT JOIN users u ON u.id = e.user_id
    ";

    private const LOOKUP_COLUMNS = "
        b.branch_name, d.department_name, g.designation_name, s.shift_name,
        u.name AS user_name, u.email AS user_email
    ";

    public function allEmployees(array $filters = []): array
    {
        $sql = "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employees e " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'e.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 'e.department_id = ?';
            $params[] = $filters['department_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(e.employee_no LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term, $term);
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.first_name, e.last_name';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employees e " . self::LOOKUP_JOINS . " WHERE e.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_employees', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_employees', $data, 'id', $id);
    }

    public function employeeNoExists(string $employeeNo): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM hrm_employees WHERE employee_no = ?", [$employeeNo]);
    }

    public function userIdInUse(int $userId, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_employees WHERE user_id = ? AND id != ?",
                [$userId, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_employees WHERE user_id = ?", [$userId]);
    }

    public function counts(): array
    {
        return [
            'total' => (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees"),
            'active' => (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE status = 'Active'"),
            'on_leave' => (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE status = 'On Leave'"),
            'terminated' => (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE status = 'Terminated'"),
        ];
    }
}
