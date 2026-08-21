<?php

namespace App\Models;

use App\Core\Model;

class PerformanceIndicator extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN performance_indicator_categories c ON c.id = i.category_id";
    private const LOOKUP_COLUMNS = "c.name AS category_name";
    private const SORTABLE = ['name' => 'i.name', 'category' => 'c.name', 'status' => 'i.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(string $search = '', string $sort = 'category', string $dir = 'asc', int $page = 1, int $perPage = 10): array
    {
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE i.name LIKE ? OR c.name LIKE ?';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM performance_indicators i " . self::LOOKUP_JOINS . $where, $params);
        $orderCol = self::SORTABLE[$sort] ?? 'c.name';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT i.*, " . self::LOOKUP_COLUMNS . " FROM performance_indicators i " . self::LOOKUP_JOINS . "{$where}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function allIndicators(): array
    {
        return $this->query(
            "SELECT i.*, " . self::LOOKUP_COLUMNS . " FROM performance_indicators i " . self::LOOKUP_JOINS . " ORDER BY c.name, i.name"
        )->fetchAll();
    }

    /**
     * Active indicators from active categories, grouped by category name --
     * this is the rubric shown on the review "conduct" screen.
     */
    public function activeGroupedByCategory(): array
    {
        $rows = $this->query(
            "SELECT i.*, " . self::LOOKUP_COLUMNS . " FROM performance_indicators i " . self::LOOKUP_JOINS . "
             WHERE i.status = 'Active' AND c.status = 'Active'
             ORDER BY c.name, i.name"
        )->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category_name'] ?? 'Uncategorized'][] = $row;
        }
        return $grouped;
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT i.*, " . self::LOOKUP_COLUMNS . " FROM performance_indicators i " . self::LOOKUP_JOINS . " WHERE i.id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('performance_indicators', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('performance_indicators', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM performance_indicators WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
