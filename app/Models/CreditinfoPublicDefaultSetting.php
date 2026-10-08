<?php

namespace App\Models;

use App\Core\Encryption;
use App\Core\Model;

/**
 * Public Defaults settings -- eligibility config (minimum_days_in_arrears,
 * minimum_outstanding_balance, borrower_notice_required,
 * notice_waiting_period_days), submission architecture (submission_method,
 * sftp_* placeholders -- Creditinfo has confirmed there is no REST API for
 * Public Defaults, only their own User Interface and, not yet specified,
 * SFTP), and tariff (listing_fee, removal_fee, vat_rate,
 * tariff_effective_date). A separate table from creditinfo_settings (CBS)
 * on purpose -- Public Defaults must never be silently switched on by a
 * CBS settings change.
 *
 * sftp_secret is the one encrypted field here (App\Core\Encryption, same
 * class every other Creditinfo/Collexia secret uses) -- every other SFTP
 * field starts blank; nothing about the eventual file layout, directory
 * structure, or schedule is invented.
 */
class CreditinfoPublicDefaultSetting extends Model
{
    private const SFTP_SECRET_KEY = 'sftp_secret';

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

    /** Blank $plaintext leaves the stored secret untouched -- "leave blank to keep, type to replace", same convention as CreditinfoSetting::setEncrypted(). */
    public function setEncryptedSftpSecret(?string $plaintext, ?int $userId): void
    {
        if ($plaintext === null || trim($plaintext) === '') {
            return;
        }
        $this->set(self::SFTP_SECRET_KEY, Encryption::encrypt($plaintext), $userId);
    }

    public function isSftpSecretSet(): bool
    {
        return $this->get(self::SFTP_SECRET_KEY) !== '';
    }

    /** For an eventual real SFTP client -- never for display. */
    public function getDecryptedSftpSecret(): ?string
    {
        $stored = $this->get(self::SFTP_SECRET_KEY);
        return $stored === '' ? null : Encryption::decrypt($stored);
    }

    public function isEnabled(): bool
    {
        return $this->get('public_defaults_enabled') === 'on';
    }

    public function submissionMethod(): string
    {
        return $this->get('submission_method', 'manual_ui');
    }

    /** True once SFTP is fully configured (host/username/secret/outbound folder all set) AND an admin has explicitly switched sftp_enabled on in Settings -- see CreditinfoPublicDefaultSftpService, the real client that acts on this. */
    public function isSftpReady(): bool
    {
        return $this->get('sftp_enabled') === 'on'
            && $this->get('sftp_host') !== ''
            && $this->get('sftp_username') !== ''
            && $this->get('sftp_outbound_directory') !== ''
            && $this->isSftpSecretSet();
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

    /**
     * Creditinfo's own vendor-assigned reference for this company (confirmed
     * "NA02629" as of 2026-10-08 -- used across the CBS API, Public Defaults
     * and CPL alike; an initial "NA02628" from 2026-10-07 was corrected the
     * next day, Creditinfo warning it would route data to a different
     * provider), used as the filename prefix in
     * CreditinfoPublicDefaultFileService::buildFilename(). "SRN" is the
     * field's own abbreviation in Creditinfo's legend, not a value -- an
     * earlier version of this method defaulted to the literal string "SRN",
     * which is exactly the wrong filename Creditinfo flagged in their
     * feedback on our first test submission. Defaults to '' now so a
     * never-configured value fails obviously instead of silently looking
     * like a real one again.
     */
    public function supplierReferenceNumber(): string
    {
        return $this->get('supplier_reference_number', '');
    }

    /** 'test' unless an admin has explicitly switched this in Settings -- never silently defaults to 'live'. Gates both the T/L filename code and whether a live batch can be generated at all. */
    public function submissionEnvironment(): string
    {
        return $this->get('submission_environment', 'test') === 'live' ? 'live' : 'test';
    }
}
