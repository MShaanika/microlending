<?php

namespace App\Models;

use App\Core\Model;

/**
 * Public Defaults settings -- eligibility config (minimum_days_in_arrears,
 * minimum_outstanding_balance, borrower_notice_required,
 * notice_waiting_period_days), integration status (public_defaults_enabled,
 * api_status, listing_endpoint, removal_endpoint, api_version,
 * vendor_documentation_received, vendor_mapping_confirmed), and tariff
 * (listing_fee, removal_fee, vat_rate, tariff_effective_date). A separate
 * table from creditinfo_settings (CBS) on purpose -- Public Defaults must
 * never be silently switched on by a CBS settings change.
 *
 * No secrets live here yet -- there is no Public Defaults API credential
 * to store until Creditinfo supplies the specification, so unlike
 * CreditinfoSetting this class has no encrypted fields.
 */
class CreditinfoPublicDefaultSetting extends Model
{
    public function get(string $key, string $default = ''): string
    {
        $value = $this->scalar("SELECT setting_value FROM creditinfo_public_default_settings WHERE setting_key = ?", [$key]);
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public function allSettings(): array
    {
        $rows = $this->query("SELECT setting_key, setting_value FROM creditinfo_public_default_settings")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    public function set(string $key, ?string $value, ?int $userId): void
    {
        $this->query(
            "INSERT INTO creditinfo_public_default_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$key, $value, $userId]
        );
    }

    public function isEnabled(): bool
    {
        return $this->get('public_defaults_enabled') === 'on';
    }

    /** Item 14: production submission stays blocked until BOTH vendor flags are explicitly confirmed -- never inferred from anything else being configured. */
    public function isReadyForProductionSubmission(): bool
    {
        return $this->get('vendor_documentation_received') === 'yes'
            && $this->get('vendor_mapping_confirmed') === 'yes';
    }

    /** Blank means "Requires Compliance Confirmation" -- see item 5; callers must not treat null as 0. */
    public function minimumDaysInArrears(): ?int
    {
        $value = $this->get('minimum_days_in_arrears');
        return $value === '' ? null : (int) $value;
    }

    public function minimumOutstandingBalance(): ?float
    {
        $value = $this->get('minimum_outstanding_balance');
        return $value === '' ? null : (float) $value;
    }

    public function borrowerNoticeRequired(): bool
    {
        return $this->get('borrower_notice_required', 'yes') !== 'no';
    }

    public function noticeWaitingPeriodDays(): ?int
    {
        $value = $this->get('notice_waiting_period_days');
        return $value === '' ? null : (int) $value;
    }

    public function listingFee(): float
    {
        return (float) $this->get('listing_fee', '0');
    }

    public function removalFee(): float
    {
        return (float) $this->get('removal_fee', '0');
    }

    public function vatRate(): float
    {
        return (float) $this->get('vat_rate', '0');
    }
}
