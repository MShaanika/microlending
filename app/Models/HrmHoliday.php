<?php

namespace App\Models;

use App\Core\Model;

class HrmHoliday extends Model
{
    public function allHolidays(?int $year = null): array
    {
        if ($year !== null) {
            return $this->query(
                "SELECT * FROM hrm_holidays WHERE YEAR(start_date) = ? ORDER BY start_date",
                [$year]
            )->fetchAll();
        }
        return $this->query("SELECT * FROM hrm_holidays ORDER BY start_date DESC")->fetchAll();
    }

    /** Holidays overlapping a date range -- feeds the monthly attendance grid report. */
    public function inRange(string $start, string $end): array
    {
        return $this->query(
            "SELECT * FROM hrm_holidays WHERE start_date <= ? AND end_date >= ? ORDER BY start_date",
            [$end, $start]
        )->fetchAll();
    }

    private const SORTABLE = ['name' => 'name', 'start_date' => 'start_date', 'holiday_type' => 'holiday_type'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(?int $year = null, string $search = '', string $sort = 'start_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if ($year !== null) {
            $where[] = 'YEAR(start_date) = ?';
            $params[] = $year;
        }
        if ($search !== '') {
            $where[] = '(name LIKE ? OR description LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM hrm_holidays" . $whereSql, $params);
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['start_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT * FROM hrm_holidays{$whereSql} ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_holidays WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_holidays', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_holidays', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_holidays WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
