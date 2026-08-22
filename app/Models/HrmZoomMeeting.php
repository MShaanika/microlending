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

    /** Whitelist of sortable columns -- $sort comes from the query string, never interpolate it directly. */
    private const SORTABLE = [
        'title' => 'm.title',
        'host' => 'u.name',
        'start_time' => 'm.start_time',
        'duration' => 'm.duration',
        'status' => 'm.status',
    ];

    /**
     * @return array{rows: array, total: int, totalPages: int}
     */
    public function paginated(array $filters = [], string $sort = 'start_time', string $dir = 'desc', int $page = 1, int $perPage = 10): array
    {
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
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM hrm_zoom_meetings m " . self::LOOKUP_JOINS . $whereSql,
            $params
        );

        $orderCol = self::SORTABLE[$sort] ?? self::SORTABLE['start_time'];
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "SELECT m.*, " . self::LOOKUP_COLUMNS . " FROM hrm_zoom_meetings m " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY $orderCol $orderDir LIMIT $perPage OFFSET $offset";

        return [
            'rows' => $this->query($sql, $params)->fetchAll(),
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
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
