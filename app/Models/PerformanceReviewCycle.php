<?php

namespace App\Models;

use App\Core\Model;

class PerformanceReviewCycle extends Model
{
    public function allCycles(): array
    {
        return $this->query("SELECT * FROM performance_review_cycles ORDER BY name")->fetchAll();
    }

    public function activeCycles(): array
    {
        return $this->query("SELECT * FROM performance_review_cycles WHERE status = 'Active' ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM performance_review_cycles WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM performance_review_cycles WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM performance_review_cycles WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('performance_review_cycles', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('performance_review_cycles', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM performance_employee_reviews WHERE review_cycle_id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM performance_review_cycles WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
