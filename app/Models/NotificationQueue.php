<?php

namespace App\Models;

use App\Core\Model;

class NotificationQueue extends Model
{
    public function paginated(string $channel = '', string $status = '', string $search = '', int $limit = 200): array
    {
        $sql = "SELECT n.*, t.template_name,
                       CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM notification_queue n
                LEFT JOIN notification_templates t ON t.id = n.template_id
                LEFT JOIN borrowers b ON b.id = n.borrower_id
                WHERE 1=1";
        $params = [];

        if ($channel !== '') {
            $sql .= " AND n.channel = ?";
            $params[] = $channel;
        }
        if ($status !== '') {
            $sql .= " AND n.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $sql .= " AND (n.recipient_name LIKE ? OR n.recipient_contact LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= " ORDER BY n.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT n.*, t.template_name, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM notification_queue n
             LEFT JOIN notification_templates t ON t.id = n.template_id
             LEFT JOIN borrowers b ON b.id = n.borrower_id
             WHERE n.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('notification_queue', $data);
    }

    public function updateStatus(int $id, string $status): bool
    {
        $data = ['status' => $status];
        if ($status === 'Sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        }
        return $this->update('notification_queue', $data, 'id', $id);
    }

    public function recordAttemptFailure(int $id, string $error): bool
    {
        $this->query(
            "UPDATE notification_queue SET attempts = attempts + 1, last_error = ? WHERE id = ?",
            [$error, $id]
        );
        return true;
    }
}
