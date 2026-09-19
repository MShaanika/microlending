<?php

namespace App\Models;

use App\Core\Model;

/**
 * Key-value settings for the pay-cycle-adjustment module (same shape as
 * CollexiaSetting/CreditinfoSetting). collection_adjustment_lead_days
 * and automatic_apply_enabled both start unconfirmed/off -- see
 * database/pay_cycle_module.sql's header comment. automatic_apply_enabled
 * is read here for display only; CollectionDateAdjustmentService::applyApproved()
 * does NOT trust this value alone (see its own hardcoded guard).
 */
class PayCycleSetting extends Model
{
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->scalar("SELECT setting_value FROM pay_cycle_settings WHERE setting_key = ?", [$key]);
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public function set(string $key, ?string $value, ?int $userId): void
    {
        $this->query(
            "INSERT INTO pay_cycle_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$key, $value, $userId]
        );
    }

    public function leadDays(): ?int
    {
        $value = $this->get('collection_adjustment_lead_days');
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function automaticApplyEnabled(): bool
    {
        return $this->get('automatic_apply_enabled', '0') === '1';
    }
}
