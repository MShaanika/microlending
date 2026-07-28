<?php

namespace App\Models;

use App\Core\Model;

class HrmLeaveApplication extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = a.employee_id
        LEFT JOIN hrm_leave_types t ON t.id = a.leave_type_id
        LEFT JOIN users u ON u.id = a.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        t.name AS leave_type_name, t.color AS leave_type_color, t.is_paid AS leave_type_is_paid,
        u.name AS approved_by_name
    ";

    public function allApplications(array $filters = []): array
    {
        $sql = "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_leave_applications a " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'a.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.created_at DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_leave_applications a " . self::LOOKUP_JOINS . " WHERE a.id = ?",
            [$id]
        );
    }

    public function overlapsExisting(int $employeeId, string $startDate, string $endDate): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM hrm_leave_applications
             WHERE employee_id = ? AND status IN ('Pending', 'Approved')
               AND start_date <= ? AND end_date >= ?",
            [$employeeId, $endDate, $startDate]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_leave_applications', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_leave_applications', $data, 'id', $id);
    }

    /**
     * Approved days only, for a given employee/leave type/calendar year --
     * the Leave Balance report's "used_days" figure.
     */
    public function usedDaysForYear(int $employeeId, int $leaveTypeId, int $year): int
    {
        return (int) $this->scalar(
            "SELECT COALESCE(SUM(total_days), 0) FROM hrm_leave_applications
             WHERE employee_id = ? AND leave_type_id = ? AND status = 'Approved' AND YEAR(start_date) = ?",
            [$employeeId, $leaveTypeId, $year]
        );
    }
}
