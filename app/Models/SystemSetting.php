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
}
