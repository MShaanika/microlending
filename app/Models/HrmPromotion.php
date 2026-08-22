<?php

namespace App\Models;

use App\Core\Model;

class HrmPromotion extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = p.employee_id
        LEFT JOIN branches pb ON pb.id = p.previous_branch_id
        LEFT JOIN hrm_departments pd ON pd.id = p.previous_department_id
        LEFT JOIN hrm_designations pg ON pg.id = p.previous_designation_id
        LEFT JOIN branches cb ON cb.id = p.current_branch_id
        LEFT JOIN hrm_departments cd ON cd.id = p.current_department_id
        LEFT JOIN hrm_designations cg ON cg.id = p.current_designation_id
        LEFT JOIN users u ON u.id = p.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        pb.branch_name AS previous_branch_name, pd.department_name AS previous_department_name, pg.designation_name AS previous_designation_name,
        cb.branch_name AS current_branch_name, cd.department_name AS current_department_name, cg.designation_name AS current_designation_name,
        u.name AS approved_by_name
    ";

    public function allPromotions(array $filters = []): array
    {
        $sql = "SELECT p.*, " . self::LOOKUP_COLUMNS . " FROM hrm_promotions p " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'p.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.effective_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['employee' => 'e.first_name', 'effective_date' => 'p.effective_date', 'status' => 'p.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'effective_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['employee_id'])) {
            $where[] = 'p.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = 'p.reason LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_promotions p " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['effective_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT p.*, " . self::LOOKUP_COLUMNS . " FROM hrm_promotions p " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT p.*, " . self::LOOKUP_COLUMNS . " FROM hrm_promotions p " . self::LOOKUP_JOINS . " WHERE p.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_promotions', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_promotions', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_promotions WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
