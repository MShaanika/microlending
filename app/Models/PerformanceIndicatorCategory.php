<?php

namespace App\Models;

use App\Core\Model;

class PerformanceIndicatorCategory extends Model
{
    private const SORTABLE = ['name' => 'name', 'status' => 'status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'name', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE name LIKE ?';
            $params[] = '%' . $search . '%';
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM performance_indicator_categories" . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? 'name';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT * FROM performance_indicator_categories{$where} ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

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
