<?php

namespace App\Models;

use App\Core\Model;

class HrmAllowance extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = a.employee_id
        LEFT JOIN hrm_allowance_types t ON t.id = a.allowance_type_id
    ";
    private const LOOKUP_COLUMNS = "CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no, t.name AS type_name";

    public function allForEmployee(int $employeeId): array
    {
        return $this->query(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_allowances a " . self::LOOKUP_JOINS . " WHERE a.employee_id = ? ORDER BY t.name",
            [$employeeId]
        )->fetchAll();
    }

    public function allAllowances(): array
    {
        return $this->query("SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_allowances a " . self::LOOKUP_JOINS . " ORDER BY e.first_name, t.name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_allowances a " . self::LOOKUP_JOINS . " WHERE a.id = ?", [$id]);
    }

    public function assignmentExists(int $employeeId, int $allowanceTypeId, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_allowances WHERE employee_id = ? AND allowance_type_id = ? AND id != ?",
                [$employeeId, $allowanceTypeId, $excludeId]
            );
        }
        return (bool) $this->scalar(
            "SELECT 1 FROM hrm_allowances WHERE employee_id = ? AND allowance_type_id = ?",
            [$employeeId, $allowanceTypeId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_allowances', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_allowances', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_allowances WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
