<?php

namespace App\Models;

use App\Core\Model;

class HrmAnnouncement extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_announcement_categories c ON c.id = a.announcement_category_id
        LEFT JOIN users u ON u.id = a.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        c.name AS category_name, u.name AS approved_by_name
    ";

    public function allAnnouncements(array $filters = []): array
    {
        $sql = "SELECT a.*, " . self::LOOKUP_COLUMNS . ",
                GROUP_CONCAT(d.department_name ORDER BY d.department_name SEPARATOR ', ') AS department_names
                FROM hrm_announcements a
                " . self::LOOKUP_JOINS . "
                LEFT JOIN hrm_announcement_departments ad ON ad.announcement_id = a.id
                LEFT JOIN hrm_departments d ON d.id = ad.department_id";
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 'a.id IN (SELECT announcement_id FROM hrm_announcement_departments WHERE department_id = ?)';
            $params[] = $filters['department_id'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY a.id ORDER BY a.start_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['title' => 'a.title', 'category' => 'c.name', 'start_date' => 'a.start_date', 'priority' => 'a.priority', 'status' => 'a.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'start_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 'a.id IN (SELECT announcement_id FROM hrm_announcement_departments WHERE department_id = ?)';
            $params[] = $filters['department_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(a.title LIKE ? OR a.description LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(DISTINCT a.id) FROM hrm_announcements a " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['start_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . ",
                GROUP_CONCAT(d.department_name ORDER BY d.department_name SEPARATOR ', ') AS department_names
                FROM hrm_announcements a
                " . self::LOOKUP_JOINS . "
                LEFT JOIN hrm_announcement_departments ad ON ad.announcement_id = a.id
                LEFT JOIN hrm_departments d ON d.id = ad.department_id"
            . $whereSql . " GROUP BY a.id ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT a.*, " . self::LOOKUP_COLUMNS . " FROM hrm_announcements a " . self::LOOKUP_JOINS . " WHERE a.id = ?",
            [$id]
        );
    }

    public function departmentIdsFor(int $id): array
    {
        return array_map('intval', $this->query(
            "SELECT department_id FROM hrm_announcement_departments WHERE announcement_id = ?",
            [$id]
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function departmentNamesFor(int $id): array
    {
        return $this->query(
            "SELECT d.department_name FROM hrm_announcement_departments ad
             JOIN hrm_departments d ON d.id = ad.department_id
             WHERE ad.announcement_id = ? ORDER BY d.department_name",
            [$id]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_announcements', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_announcements', $data, 'id', $id);
    }

    public function syncDepartments(int $id, array $departmentIds): void
    {
        $this->query("DELETE FROM hrm_announcement_departments WHERE announcement_id = ?", [$id]);
        foreach (array_unique(array_map('intval', $departmentIds)) as $deptId) {
            if ($deptId > 0) {
                $this->insert('hrm_announcement_departments', ['announcement_id' => $id, 'department_id' => $deptId]);
            }
        }
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_announcements WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
