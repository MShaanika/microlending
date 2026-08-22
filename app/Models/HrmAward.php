<?php

namespace App\Models;

use App\Core\Model;

class HrmAward extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = a.employee_id
        LEFT JOIN hrm_award_types t ON t.id = a.award_type_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        t.name AS award_type_name
    ";

    public function allAwards(array $filters = []): array
    {
        $sql = "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_awards a " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'a.employee_id = ?';
            $params[] = $filters['employee_id'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.award_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['employee' => 'e.first_name', 'award_type' => 't.name', 'award_date' => 'a.award_date'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'award_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['employee_id'])) {
            $where[] = 'a.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR a.description LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_awards a " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['award_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_awards a " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_awards a " . self::LOOKUP_JOINS . " WHERE a.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_awards', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_awards', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_awards WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
