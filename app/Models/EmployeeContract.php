<?php

namespace App\Models;

use App\Core\Model;

/** One generated Employment Contract per row -- see database/employment_contract_templates.sql's header comment on why position/salary/duties are snapshotted (_at_time) rather than re-read live. */
class EmployeeContract extends Model
{
    public function forEmployee(int $employeeId): array
    {
        return $this->query(
            "SELECT ec.*, u.name AS generated_by_name
             FROM employee_contracts ec
             LEFT JOIN users u ON u.id = ec.generated_by
             WHERE ec.employee_id = ? ORDER BY ec.generated_at DESC",
            [$employeeId]
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM employee_contracts WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('employee_contracts', $data);
    }
}
