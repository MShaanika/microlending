<?php

namespace App\Models;

use App\Core\Model;

class NotificationSetting extends Model
{
    public function allSettings(string $channel = ''): array
    {
        $sql = "SELECT * FROM notification_settings";
        $params = [];
        if ($channel !== '') {
            $sql .= " WHERE channel = ?";
            $params[] = $channel;
        }
        $sql .= " ORDER BY setting_key";

        return $this->all($sql, $params);
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->scalar("SELECT setting_value FROM notification_settings WHERE setting_key = ?", [$key]);
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public function set(string $key, string $value, string $channel, ?int $userId): void
    {
        $this->query(
            "INSERT INTO notification_settings (setting_key, setting_value, channel, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), channel = VALUES(channel), updated_by = VALUES(updated_by)",
            [$key, $value, $channel, $userId]
        );
    }
}
