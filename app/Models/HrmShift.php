<?php

namespace App\Models;

use App\Core\Model;

class HrmShift extends Model
{
    public function allShifts(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM hrm_shifts";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY shift_name";

        return $this->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_shifts WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM hrm_shifts WHERE shift_name = ? AND id != ?",
                [$name, $excludeId]
            );
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_shifts WHERE shift_name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_shifts', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_shifts', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_employees WHERE shift_id = ?", [$id]);
    }
}
