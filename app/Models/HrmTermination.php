<?php

namespace App\Models;

use App\Core\Model;

class HrmTermination extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = t.employee_id
        LEFT JOIN hrm_termination_types tt ON tt.id = t.termination_type_id
        LEFT JOIN users u ON u.id = t.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        tt.name AS termination_type_name, u.name AS approved_by_name
    ";

    public function allTerminations(array $filters = []): array
    {
        $sql = "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM hrm_terminations t " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 't.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.created_at DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['employee' => 'e.first_name', 'termination_date' => 't.termination_date', 'status' => 't.status', 'created_at' => 't.created_at'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'created_at', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['employee_id'])) {
            $where[] = 't.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 't.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(t.reason LIKE ? OR t.description LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_terminations t " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['created_at'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM hrm_terminations t " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT t.*, " . self::LOOKUP_COLUMNS . " FROM hrm_terminations t " . self::LOOKUP_JOINS . " WHERE t.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_terminations', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_terminations', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_terminations WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
