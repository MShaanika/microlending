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
