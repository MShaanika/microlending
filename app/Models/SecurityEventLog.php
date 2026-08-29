<?php

namespace App\Models;

use App\Core\Model;

/** Read-side for security_events (written via App\Core\SecurityEvent::record()). Named "...Log" to avoid any ambiguity with that recorder class. */
class SecurityEventLog extends Model
{
    private const LOOKUP_JOINS = "LEFT JOIN users u ON u.id = e.user_id";
    private const LOOKUP_COLUMNS = "u.name AS user_name";

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['event_type'])) {
            $where[] = 'e.event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'e.severity = ?';
            $params[] = $filters['severity'];
        }
        if (!empty($filters['ip'])) {
            $where[] = 'e.ip_address = ?';
            $params[] = $filters['ip'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'e.user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'e.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'e.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = '(e.description LIKE ? OR e.attempted_login LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM security_events e " . self::LOOKUP_JOINS . $whereSql, $params);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM security_events e " . self::LOOKUP_JOINS
            . $whereSql . " ORDER BY e.created_at DESC, e.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function distinctEventTypes(): array
    {
        return $this->query("SELECT DISTINCT event_type FROM security_events ORDER BY event_type")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function countToday(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM security_events WHERE DATE(created_at) = CURDATE()");
    }

    public function countByTypeToday(string $eventType): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM security_events WHERE event_type = ? AND DATE(created_at) = CURDATE()", [$eventType]);
    }

    /** Hourly failed-login counts for the last 24h -- backs the Overview trend chart. */
    public function failedLoginsByHour(): array
    {
        return $this->all(
            "SELECT DATE_FORMAT(created_at, '%H:00') AS hour_label, COUNT(*) AS total
             FROM security_events
             WHERE event_type = 'LOGIN_FAILED' AND created_at >= NOW() - INTERVAL 24 HOUR
             GROUP BY hour_label ORDER BY hour_label"
        );
    }

    public function recentHighSeverity(int $limit = 10): array
    {
        return $this->all(
            "SELECT e.*, " . self::LOOKUP_COLUMNS . " FROM security_events e " . self::LOOKUP_JOINS . "
             WHERE e.severity IN ('High','Critical') ORDER BY e.created_at DESC LIMIT " . max(1, $limit)
        );
    }
}
