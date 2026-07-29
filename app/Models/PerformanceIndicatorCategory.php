<?php

namespace App\Models;

use App\Core\Model;

class PerformanceIndicatorCategory extends Model
{
    public function allCategories(): array
    {
        return $this->query("SELECT * FROM performance_indicator_categories ORDER BY name")->fetchAll();
    }

    public function activeCategories(): array
    {
        return $this->query("SELECT * FROM performance_indicator_categories WHERE status = 'Active' ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM performance_indicator_categories WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM performance_indicator_categories WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM performance_indicator_categories WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('performance_indicator_categories', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('performance_indicator_categories', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM performance_indicators WHERE category_id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM performance_indicator_categories WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
