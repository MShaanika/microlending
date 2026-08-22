<?php

namespace App\Models;

use App\Core\Model;

class HrmComplaint extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = c.employee_id
        LEFT JOIN hrm_employees ae ON ae.id = c.against_employee_id
        LEFT JOIN hrm_complaint_types t ON t.id = c.complaint_type_id
        LEFT JOIN users u ON u.id = c.resolved_by
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        CONCAT(ae.first_name, ' ', ae.last_name) AS against_employee_name,
        t.name AS complaint_type_name, u.name AS resolved_by_name
    ";

    public function allComplaints(array $filters = []): array
    {
        $sql = "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM hrm_complaints c " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'c.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.complaint_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['employee' => 'e.first_name', 'subject' => 'c.subject', 'complaint_date' => 'c.complaint_date', 'status' => 'c.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'complaint_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['employee_id'])) {
            $where[] = 'c.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.subject LIKE ? OR c.description LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_complaints c " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['complaint_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM hrm_complaints c " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT c.*, " . self::LOOKUP_COLUMNS . " FROM hrm_complaints c " . self::LOOKUP_JOINS . " WHERE c.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_complaints', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_complaints', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_complaints WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
