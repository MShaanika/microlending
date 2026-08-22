<?php

namespace App\Models;

use App\Core\Model;

class HrmWarning extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = w.employee_id
        LEFT JOIN users u ON u.id = w.warning_by
        LEFT JOIN hrm_warning_types t ON t.id = w.warning_type_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        u.name AS warning_by_name, t.name AS warning_type_name
    ";

    public function allWarnings(array $filters = []): array
    {
        $sql = "SELECT w.*, " . self::LOOKUP_COLUMNS . " FROM hrm_warnings w " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'w.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'w.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY w.warning_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['employee' => 'e.first_name', 'subject' => 'w.subject', 'severity' => 'w.severity', 'warning_date' => 'w.warning_date', 'status' => 'w.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'warning_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['employee_id'])) {
            $where[] = 'w.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'w.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(w.subject LIKE ? OR w.description LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_warnings w " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['warning_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT w.*, " . self::LOOKUP_COLUMNS . " FROM hrm_warnings w " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT w.*, " . self::LOOKUP_COLUMNS . " FROM hrm_warnings w " . self::LOOKUP_JOINS . " WHERE w.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_warnings', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_warnings', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_warnings WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
