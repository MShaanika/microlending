<?php

namespace App\Models;

use App\Core\Model;

class HrmZoomMeeting extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN users u ON u.id = m.host_id";
    private const LOOKUP_COLUMNS = "u.name AS host_name";

    public function allMeetings(array $filters = []): array
    {
        $sql = "SELECT m.*, " . self::LOOKUP_COLUMNS . " FROM hrm_zoom_meetings m " . self::LOOKUP_JOINS;
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'm.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(m.title LIKE ? OR m.meeting_id LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term);
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY m.start_time DESC';

        return $this->query($sql, $params)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT m.*, " . self::LOOKUP_COLUMNS . " FROM hrm_zoom_meetings m " . self::LOOKUP_JOINS . " WHERE m.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_zoom_meetings', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_zoom_meetings', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_zoom_meetings WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
