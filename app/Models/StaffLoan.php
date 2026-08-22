<?php

namespace App\Models;

use App\Core\Model;

class StaffLoan extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = l.employee_id
        LEFT JOIN hrm_staff_loan_types t ON t.id = l.staff_loan_type_id
        LEFT JOIN users u ON u.id = l.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        t.name AS type_name, u.name AS approved_by_name
    ";

    public function allLoans(array $filters = []): array
    {
        $sql = "SELECT l.*, " . self::LOOKUP_COLUMNS . " FROM hrm_staff_loans l " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'l.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY l.created_at DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['employee' => 'e.first_name', 'title' => 'l.title', 'type' => 't.name', 'principal' => 'l.principal_amount', 'outstanding' => 'l.outstanding_balance', 'status' => 'l.status', 'created_at' => 'l.created_at'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'created_at', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['employee_id'])) {
            $where[] = 'l.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'l.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = 'l.title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_staff_loans l " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['created_at'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT l.*, " . self::LOOKUP_COLUMNS . " FROM hrm_staff_loans l " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT l.*, " . self::LOOKUP_COLUMNS . " FROM hrm_staff_loans l " . self::LOOKUP_JOINS . " WHERE l.id = ?",
            [$id]
        );
    }

    /**
     * Loans eligible for a payroll deduction in a run whose pay period
     * ends on $asOfDate: Active, not yet fully repaid, and already started.
     */
    public function activeForEmployeeAsOf(int $employeeId, string $asOfDate): array
    {
        return $this->query(
            "SELECT * FROM hrm_staff_loans
             WHERE employee_id = ? AND status = 'Active' AND outstanding_balance > 0 AND start_date <= ?",
            [$employeeId, $asOfDate]
        )->fetchAll();
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_staff_loans', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_staff_loans', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_staff_loans WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
