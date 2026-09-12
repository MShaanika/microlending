<?php

namespace App\Models;

use App\Core\Model;

/**
 * CPLv1.1 (Credit Providers Layout) configuration -- supplier reference
 * number, trading name, recipient, version, submission environment, agreed
 * billing date / deadline rule, and the one-month/multi-month account-type
 * code mapping. A separate key-value table from creditinfo_settings (CBS)
 * and creditinfo_public_default_settings, mirroring their exact shape, since
 * CPL reporting is a distinct regulatory obligation from the CBS REST
 * integration and Public Defaults.
 *
 * account_type_mapping_confirmed defaults 'no': the CPLv1-1.pdf documents
 * "1-month personal loan -> M, >1-month -> P" as the account-type rule, but
 * this project has not had that specific mapping confirmed in writing by
 * Creditinfo/the supplier for Solid Desert's own book. The codes themselves
 * (account_type_one_month / account_type_multi_month) are configurable so an
 * admin can correct them without a deploy if the bureau specifies otherwise;
 * the confirmed flag only drives a warning banner in the export UI -- it
 * does not block the monthly extract, since CPL submission is a standing
 * regulatory deadline that must keep working with the documented default
 * while confirmation is pending.
 */
class CplSetting extends Model
{
    public function get(string $key, string $default = ''): string
    {
        $value = $this->scalar("SELECT setting_value FROM cpl_settings WHERE setting_key = ?", [$key]);
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public function allSettings(): array
    {
        $rows = $this->query("SELECT setting_key, setting_value FROM cpl_settings")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    public function set(string $key, ?string $value, ?int $userId): void
    {
        $this->query(
            "INSERT INTO cpl_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$key, $value, $userId]
        );
    }

    public function supplierReferenceNumber(): string
    {
        return $this->get('supplier_reference_number');
    }

    public function tradingName(): string
    {
        return $this->get('trading_name', 'Solid Desert Cash Loan');
    }

    public function recipient(): string
    {
        return $this->get('recipient', 'ALL');
    }

    public function cplVersion(): string
    {
        return $this->get('cpl_version', '06');
    }

    public function submissionEnvironment(): string
    {
        return $this->get('submission_environment', 'uat');
    }

    public function isProductionEnvironment(): bool
    {
        return $this->submissionEnvironment() === 'production';
    }

    /** Day of the month (1-31) the bureau has agreed as the billing date -- blank means "not yet agreed", never guessed. */
    public function agreedBillingDate(): ?int
    {
        $value = $this->get('agreed_billing_date');
        return $value === '' ? null : (int) $value;
    }

    public function monthlySubmissionDeadlineRule(): string
    {
        return $this->get('monthly_submission_deadline_rule', '5 working days after the agreed billing date');
    }

    public function oneMonthAccountType(): string
    {
        return $this->get('account_type_one_month', 'M');
    }

    public function multiMonthAccountType(): string
    {
        return $this->get('account_type_multi_month', 'P');
    }

    public function isAccountTypeMappingConfirmed(): bool
    {
        return $this->get('account_type_mapping_confirmed') === 'yes';
    }
}
