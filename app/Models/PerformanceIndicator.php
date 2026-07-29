<?php

namespace App\Models;

use App\Core\Model;

class PerformanceIndicator extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN performance_indicator_categories c ON c.id = i.category_id";
    private const LOOKUP_COLUMNS = "c.name AS category_name";

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
