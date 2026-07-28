<?php

namespace App\Models;

use App\Core\Model;

class HrmLeaveType extends Model
{
    public function allLeaveTypes(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM hrm_leave_types";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name";

        return $this->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_leave_types WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM hrm_leave_types WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_leave_types WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_leave_types', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_leave_types', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_leave_applications WHERE leave_type_id = ?", [$id]);
    }
}
