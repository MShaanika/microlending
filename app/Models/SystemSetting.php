<?php

namespace App\Models;

use App\Core\Model;

class SystemSetting extends Model
{
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->scalar('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
        return $value !== false ? (string) $value : $default;
    }

    /** Locks the given setting row for the caller's transaction -- used as a serialization point for batch operations with no single business row of their own to lock. */
    public function lockRow(string $key): void
    {
        $this->one('SELECT setting_value FROM system_settings WHERE setting_key = ? FOR UPDATE', [$key]);
    }

    public function set(string $key, string $value, string $moduleName, ?int $userId): void
    {
        $this->query(
            "INSERT INTO system_settings (setting_key, setting_value, module_name, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), module_name = VALUES(module_name), updated_by = VALUES(updated_by)",
            [$key, $value, $moduleName, $userId]
        );
    }
}
