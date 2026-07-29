<?php

namespace App\Models;

use App\Core\Model;

class HrmZoomSetting extends Model
{
    public function get(string $key, string $default = ''): string
    {
        $value = $this->scalar("SELECT setting_value FROM hrm_zoom_settings WHERE setting_key = ?", [$key]);
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public function allSettings(): array
    {
        $rows = $this->query("SELECT setting_key, setting_value FROM hrm_zoom_settings")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    public function set(string $key, ?string $value, ?int $userId): void
    {
        $this->query(
            "INSERT INTO hrm_zoom_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$key, $value, $userId]
        );
    }

    public function isEnabled(): bool
    {
        return $this->get('zoom_enabled') === 'on';
    }
}
