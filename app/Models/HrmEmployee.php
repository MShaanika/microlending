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
        s.start_time AS shift_start_time, s.end_time AS shift_end_time,
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

    /** Whitelist of sortable columns -- $sort comes from the query string, never interpolate it directly. */
    private const SORTABLE = [
        'employee_no' => 'e.employee_no',
        'name' => 'e.first_name',
        'department' => 'd.department_name',
        'designation' => 'g.designation_name',
        'branch' => 'b.branch_name',
        'status' => 'e.status',
        'date_of_joining' => 'e.date_of_joining',
    ];

    /**
     * @return array{rows: array, total: int, totalPages: int}
     */
    public function paginated(array $filters = [], string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
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
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_employees e " . self::LOOKUP_JOINS . $whereSql,
            $params
        );

        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['name'];
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employees e " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY $orderCol $orderDir, e.last_name $orderDir LIMIT $perPage OFFSET $offset";

        return [
            'rows' => $this->query($sql, $params)->fetchAll(),
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
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

    public function findByUserId(int $userId): ?array
    {
        return $this->one(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employees e " . self::LOOKUP_JOINS . " WHERE e.user_id = ?",
            [$userId]
        );
    }

    public function employeeNoExists(string $employeeNo): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM hrm_employees WHERE employee_no = ?", [$employeeNo]);
    }

    public function referralCodeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_employees WHERE referral_code = ? AND id != ?",
                [$code, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_employees WHERE referral_code = ?", [$code]);
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

    /** Only active, commission-eligible agents match -- a code alone isn't enough to earn attribution. */
    public function findByReferralCode(string $code): ?array
    {
        return $this->one(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employees e " . self::LOOKUP_JOINS . "
             WHERE e.referral_code = ? AND e.is_commission_agent = 1 AND e.status = 'Active'",
            [$code]
        );
    }

    public function commissionAgents(): array
    {
        return $this->query(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM hrm_employees e " . self::LOOKUP_JOINS . "
             WHERE e.is_commission_agent = 1 ORDER BY e.first_name, e.last_name"
        )->fetchAll();
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
