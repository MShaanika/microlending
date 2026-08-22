<?php

namespace App\Models;

use App\Core\Model;

class HrmEvent extends Model
{
    private const LOOKUP_JOINS = "
        LEFT JOIN hrm_event_types t ON t.id = e.event_type_id
        LEFT JOIN users u ON u.id = e.approved_by
    ";
    private const LOOKUP_COLUMNS = "
        t.name AS event_type_name, u.name AS approved_by_name
    ";

    public function allEvents(array $filters = []): array
    {
        $sql = "SELECT e.*, " . self::LOOKUP_COLUMNS . ",
                GROUP_CONCAT(d.department_name ORDER BY d.department_name SEPARATOR ', ') AS department_names
                FROM hrm_events e
                " . self::LOOKUP_JOINS . "
                LEFT JOIN hrm_event_departments ed ON ed.event_id = e.id
                LEFT JOIN hrm_departments d ON d.id = ed.department_id";
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 'e.id IN (SELECT event_id FROM hrm_event_departments WHERE department_id = ?)';
            $params[] = $filters['department_id'];
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY e.id ORDER BY e.start_date DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    private const SORTABLE = ['title' => 'e.title', 'event_type' => 't.name', 'start_date' => 'e.start_date', 'location' => 'e.location', 'status' => 'e.status'];

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], string $sort = 'start_date', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 'e.id IN (SELECT event_id FROM hrm_event_departments WHERE department_id = ?)';
            $params[] = $filters['department_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(e.title LIKE ? OR e.location LIKE ? OR e.description LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(DISTINCT e.id) FROM hrm_events e " . self::LOOKUP_JOINS . $whereSql,
            $params
        );
        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['start_date'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->query(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . ",
                GROUP_CONCAT(d.department_name ORDER BY d.department_name SEPARATOR ', ') AS department_names
                FROM hrm_events e
                " . self::LOOKUP_JOINS . "
                LEFT JOIN hrm_event_departments ed ON ed.event_id = e.id
                LEFT JOIN hrm_departments d ON d.id = ed.department_id"
            . $whereSql . " GROUP BY e.id ORDER BY {$orderCol} {$orderDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM hrm_events e " . self::LOOKUP_JOINS . " WHERE e.id = ?",
            [$id]
        );
    }

    public function departmentIdsFor(int $id): array
    {
        return array_map('intval', $this->query(
            "SELECT department_id FROM hrm_event_departments WHERE event_id = ?",
            [$id]
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function departmentNamesFor(int $id): array
    {
        return $this->query(
            "SELECT d.department_name FROM hrm_event_departments ed
             JOIN hrm_departments d ON d.id = ed.department_id
             WHERE ed.event_id = ? ORDER BY d.department_name",
            [$id]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_events', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_events', $data, 'id', $id);
    }

    public function syncDepartments(int $id, array $departmentIds): void
    {
        $this->query("DELETE FROM hrm_event_departments WHERE event_id = ?", [$id]);
        foreach (array_unique(array_map('intval', $departmentIds)) as $deptId) {
            if ($deptId > 0) {
                $this->insert('hrm_event_departments', ['event_id' => $id, 'department_id' => $deptId]);
            }
        }
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_events WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
