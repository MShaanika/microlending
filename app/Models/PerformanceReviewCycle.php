<?php

namespace App\Models;

use App\Core\Model;

class PerformanceReviewCycle extends Model
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
        $total = (int) $this->scalar("SELECT COUNT(*) FROM performance_review_cycles" . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? 'name';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT * FROM performance_review_cycles{$where} ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

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
