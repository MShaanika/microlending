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

    private const SORTABLE = ['employee' => 'e.first_name', 'leave_type' => 't.name', 'start_date' => 'a.start_date', 'total_days' => 'a.total_days', 'status' => 'a.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'start_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
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
        if (!empty($filters['search'])) {
            $where[] = 'a.reason LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_leave_applications a " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['start_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_leave_applications a " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_leave_applications a " . self::LOOKUP_JOINS . " WHERE a.id = ?",
            [$id]
        );
    }

    /**
     * All approved leave applications overlapping a date range, keyed by
     * employee_id -- feeds the monthly attendance grid report.
     */
    public function approvedInRange(string $start, string $end): array
    {
        $rows = $this->query(
            "SELECT employee_id, start_date, end_date FROM hrm_leave_applications
             WHERE status = 'Approved' AND start_date <= ? AND end_date >= ?",
            [$end, $start]
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['employee_id']][] = $row;
        }
        return $map;
    }

    public function isOnApprovedLeave(int $employeeId, string $date): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM hrm_leave_applications
             WHERE employee_id = ? AND status = 'Approved' AND start_date <= ? AND end_date >= ?",
            [$employeeId, $date, $date]
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
