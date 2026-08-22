<?php

namespace App\Models;

use App\Core\Model;

class HrmDeduction extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = d.employee_id
        LEFT JOIN hrm_deduction_types t ON t.id = d.deduction_type_id
    ";
    private const LOOKUP_COLUMNS = "CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no, t.name AS type_name";

    public function allForEmployee(int $employeeId): array
    {
        return $this->query(
            "SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_deductions d " . self::LOOKUP_JOINS . " WHERE d.employee_id = ? ORDER BY t.name",
            [$employeeId]
        )->fetchAll();
    }

    public function allDeductions(): array
    {
        return $this->query("SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_deductions d " . self::LOOKUP_JOINS . " ORDER BY e.first_name, t.name")->fetchAll();
    }

    private const SORTABLE = ['employee' => 'e.first_name', 'type_name' => 't.name', 'type' => 'd.type', 'amount' => 'd.amount'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'employee', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['employee_id'])) {
            $where[] = 'd.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR t.name LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_deductions d " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['employee'];
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_deductions d " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT d.*, " . self::LOOKUP_COLUMNS . " FROM hrm_deductions d " . self::LOOKUP_JOINS . " WHERE d.id = ?", [$id]);
    }

    public function assignmentExists(int $employeeId, int $deductionTypeId, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_deductions WHERE employee_id = ? AND deduction_type_id = ? AND id != ?",
                [$employeeId, $deductionTypeId, $excludeId]
            );
        }
        return (bool) $this->scalar(
            "SELECT 1 FROM hrm_deductions WHERE employee_id = ? AND deduction_type_id = ?",
            [$employeeId, $deductionTypeId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_deductions', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_deductions', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_deductions WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
