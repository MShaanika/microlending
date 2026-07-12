<?php

namespace App\Models;

use App\Core\Model;

class NotificationTemplate extends Model
{
    public function allTemplates(string $channel = '', bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM notification_templates WHERE 1=1";
        $params = [];

        if ($channel !== '') {
            $sql .= " AND channel = ?";
            $params[] = $channel;
        }
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY template_name";

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM notification_templates WHERE id = ?", [$id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->one("SELECT * FROM notification_templates WHERE template_code = ? AND is_active = 1", [$code]);
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM notification_templates WHERE template_code = ? AND id != ?", [$code, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM notification_templates WHERE template_code = ?", [$code]);
    }

    public function create(array $data): int
    {
        return $this->insert('notification_templates', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('notification_templates', $data, 'id', $id);
    }
}
