<?php

namespace App\Models;

use App\Core\Model;

class RecruitmentSetting extends Model
{
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->scalar("SELECT setting_value FROM recruitment_settings WHERE setting_key = ?", [$key]);
        return $value !== false ? $value : $default;
    }

    public function allSettings(): array
    {
        $rows = $this->query("SELECT setting_key, setting_value FROM recruitment_settings")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    public function set(string $key, ?string $value): void
    {
        $exists = (bool) $this->scalar("SELECT 1 FROM recruitment_settings WHERE setting_key = ?", [$key]);
        if ($exists) {
            $this->query("UPDATE recruitment_settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
        } else {
            $this->insert('recruitment_settings', ['setting_key' => $key, 'setting_value' => $value]);
        }
    }
}
