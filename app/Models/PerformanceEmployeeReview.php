<?php

namespace App\Models;

use App\Core\Model;

class PerformanceEmployeeReview extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_employees e ON e.id = r.employee_id
        LEFT JOIN users u ON u.id = r.reviewer_id
        LEFT JOIN performance_review_cycles c ON c.id = r.review_cycle_id
    ";
    private const LOOKUP_COLUMNS = "
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name, e.employee_no,
        u.name AS reviewer_name, c.name AS review_cycle_name
    ";

    private const SORTABLE = [
        'employee' => 'e.first_name',
        'reviewer' => 'u.name',
        'review_cycle' => 'c.name',
        'review_date' => 'r.review_date',
        'status' => 'r.status',
    ];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $search = '', string $sort = 'review_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "CONCAT(e.first_name, ' ', e.last_name) LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if (!empty($filters['employee_id'])) {
            $where[] = 'r.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['review_cycle_id'])) {
            $where[] = 'r.review_cycle_id = ?';
            $params[] = $filters['review_cycle_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM performance_employee_reviews r " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? 'r.review_date';
        $orderDir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT r.*, " . self::LOOKUP_COLUMNS . " FROM performance_employee_reviews r " . self::LOOKUP_JOINS . "{$whereSql}
             ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function allReviews(array $filters = []): array
    {
        $sql = "SELECT r.*, " . self::LOOKUP_COLUMNS . " FROM performance_employee_reviews r " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['employee_id'])) {
            $where[] = 'r.employee_id = ?';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['review_cycle_id'])) {
            $where[] = 'r.review_cycle_id = ?';
            $params[] = $filters['review_cycle_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.review_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT r.*, " . self::LOOKUP_COLUMNS . " FROM performance_employee_reviews r " . self::LOOKUP_JOINS . " WHERE r.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('performance_employee_reviews', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('performance_employee_reviews', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM performance_employee_reviews WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }

    /** {indicator_id: 1-5, ...} decoded from the stored JSON text, or []. */
    public static function ratingsMap(array $review): array
    {
        if (empty($review['rating'])) {
            return [];
        }
        $decoded = json_decode($review['rating'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Mean of ratings > 0 (unrated indicators excluded from both sides),
     * rounded to 1 decimal. Matches the reference module's accessor exactly.
     */
    public static function averageRating(array $review): ?float
    {
        $rated = array_filter(self::ratingsMap($review), fn($r) => (int) $r > 0);
        if (empty($rated)) {
            return null;
        }
        return round(array_sum($rated) / count($rated), 1);
    }
}
