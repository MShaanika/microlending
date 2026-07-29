<?php

namespace App\Models;

use App\Core\Model;

class PerformanceGoalType extends Model
{
    public function allTypes(): array
    {
        return $this->query("SELECT * FROM performance_goal_types ORDER BY name")->fetchAll();
    }

    public function activeTypes(): array
    {
        return $this->query("SELECT * FROM performance_goal_types WHERE status = 'Active' ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM performance_goal_types WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM performance_goal_types WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM performance_goal_types WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('performance_goal_types', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('performance_goal_types', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM performance_employee_goals WHERE goal_type_id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM performance_goal_types WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
