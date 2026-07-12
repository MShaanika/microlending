<?php

namespace App\Models;

use App\Core\Model;

class NotificationLog extends Model
{
    public function forQueueItem(int $queueId): array
    {
        return $this->all("SELECT * FROM notification_logs WHERE notification_id = ? ORDER BY id DESC", [$queueId]);
    }

    public function create(array $data): int
    {
        return $this->insert('notification_logs', $data);
    }
}
