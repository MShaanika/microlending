<?php

namespace App\Models;

use App\Core\Model;

class PerformanceEmployeeGoal extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = g.employee_id
        LEFT JOIN performance_goal_types t ON t.id = g.goal_type_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        t.name AS goal_type_name
    ";

    private const SORTABLE = [
        'employee' => 'e.first_name',
        'title' => 'g.title',
        'goal_type' => 't.name',
        'end_date' => 'g.end_date',
        'status' => 'g.status',
    ];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $search = '', string $sort = 'end_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(g.title LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like);
        }
        if (!empty($filters['employee_id'])) {
            $where[] = 'g.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['goal_type_id'])) {
            $where[] = 'g.goal_type_id = ?';
            $params[] = $filters['goal_type_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'g.status = ?';
            $params[] = $filters['status'];
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM performance_employee_goals g " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? 'g.end_date';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT g.*, " . self::LOOKUP_COLUMNS . " FROM performance_employee_goals g " . self::LOOKUP_JOINS . "{$whereSql}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function allGoals(array $filters = []): array
    {
        $sql = "SELECT g.*, " . self::LOOKUP_COLUMNS . " FROM performance_employee_goals g " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'g.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['goal_type_id'])) {
            $where[] = 'g.goal_type_id = ?';
            $params[] = $filters['goal_type_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'g.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY g.end_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT g.*, " . self::LOOKUP_COLUMNS . " FROM performance_employee_goals g " . self::LOOKUP_JOINS . " WHERE g.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('performance_employee_goals', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('performance_employee_goals', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM performance_employee_goals WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * min(100, progress/target*100), matching the reference module's
     * accessor. Only meaningful when target happens to be a plain number;
     * returns null (render as "--") otherwise.
     */
    public static function progressPercentage(array $goal): ?float
    {
        $target = is_numeric($goal['target']) ? (float) $goal['target'] : null;
        if ($target === null || $target <= 0) {
            return null;
        }
        return min(100, round((float) $goal['progress'] / $target * 100, 1));
    }
}
