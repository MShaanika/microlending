<?php

namespace App\Models;

use App\Core\Model;

class HrmDesignation extends Model
{
    public function allDesignations(bool $activeOnly = false): array
    {
        $sql = "SELECT g.*, b.branch_name, d.department_name
                FROM hrm_designations g
                LEFT JOIN branches b ON b.id = g.branch_id
                LEFT JOIN hrm_departments d ON d.id = g.department_id";
        if ($activeOnly) {
            $sql .= " WHERE g.is_active = 1";
        }
        $sql .= " ORDER BY g.designation_name";

        return $this->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT g.*, b.branch_name, d.department_name
             FROM hrm_designations g
             LEFT JOIN branches b ON b.id = g.branch_id
             LEFT JOIN hrm_departments d ON d.id = g.department_id
             WHERE g.id = ?",
            [$id]
        );
    }

    public function byDepartment(int $departmentId): array
    {
        return $this->query(
            "SELECT * FROM hrm_designations WHERE department_id = ? AND is_active = 1 ORDER BY designation_name",
            [$departmentId]
        )->fetchAll();
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_designations WHERE designation_name = ? AND id != ?",
                [$name, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_designations WHERE designation_name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_designations', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_designations', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE designation_id = ?", [$id]);
    }
}
