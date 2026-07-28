<?php

namespace App\Models;

use App\Core\Model;

class HrmDepartment extends Model
{
    public function allDepartments(bool $activeOnly = false): array
    {
        $sql = "SELECT d.*, b.branch_name
                FROM hrm_departments d
                LEFT JOIN branches b ON b.id = d.branch_id";
        if ($activeOnly) {
            $sql .= " WHERE d.is_active = 1";
        }
        $sql .= " ORDER BY d.department_name";

        return $this->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, b.branch_name
             FROM hrm_departments d
             LEFT JOIN branches b ON b.id = d.branch_id
             WHERE d.id = ?",
            [$id]
        );
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_departments WHERE department_name = ? AND id != ?",
                [$name, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_departments WHERE department_name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_departments', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_departments', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        $employees = (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE department_id = ?", [$id]);
        $designations = (int) $this->scalar("SELECT COUNT(*) FROM hrm_designations WHERE department_id = ?", [$id]);
        return $employees + $designations;
    }
}
