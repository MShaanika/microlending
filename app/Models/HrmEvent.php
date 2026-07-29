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
