<?php

namespace App\Models;

use App\Core\Model;

/** Credentials for the Collexia EnDO V3 REST API, editable via /collexia/settings. */
class CollexiaSetting extends Model
{
    public function get(string $key, string $default = ''): string
    {
        $value = $this->scalar("SELECT setting_value FROM collexia_settings WHERE setting_key = ?", [$key]);
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public function allSettings(): array
    {
        $rows = $this->query("SELECT setting_key, setting_value FROM collexia_settings")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    public function set(string $key, ?string $value, ?int $userId): void
    {
        $this->query(
            "INSERT INTO collexia_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$key, $value, $userId]
        );
    }

    public function isEnabled(): bool
    {
        return $this->get('collexia_enabled') === 'on';
    }

    /** True once every field the API client needs to make a call has a value. */
    public function isConfigured(): bool
    {
        $all = $this->allSettings();
        foreach (['collexia_base_url', 'collexia_merchant_gid', 'collexia_remote_gid', 'collexia_system_username', 'collexia_front_end_username'] as $key) {
            if (trim((string) ($all[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }
}
