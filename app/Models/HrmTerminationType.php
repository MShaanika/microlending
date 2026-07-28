<?php

namespace App\Models;

use App\Core\Model;

class HrmTerminationType extends Model
{
    public function allTypes(): array
    {
        return $this->query("SELECT * FROM hrm_termination_types ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_termination_types WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM hrm_termination_types WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_termination_types WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_termination_types', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_termination_types', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_terminations WHERE termination_type_id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_termination_types WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
