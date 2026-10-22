<?php

namespace App\Models;

use App\Core\Encryption;
use App\Core\Model;

/**
 * Collexia EnDO V3 settings, editable via /collexia/settings. Plain fields
 * (base_url, GIDs, usernames, client_id) are stored as-is; Password and
 * Client Secret are encrypted (App\Core\Encryption) and never leave this
 * class in plain text -- only isPasswordSet()/isClientSecretSet() (presence)
 * and getDecrypted() (for actual API use, not display).
 */
class CollexiaSetting extends Model
{
    private const BASE_FIELDS = [
        'collexia_base_url' => 'Host / Base URL',
        'collexia_merchant_gid' => 'Merchant GID',
        'collexia_remote_gid' => 'Remote GID',
        'collexia_system_username' => 'Username',
        'collexia_client_id' => 'Client ID',
    ];

    private const FRONT_END_USERNAME_KEY = 'collexia_front_end_username';
    private const V4_FIELDS_KEY = 'collexia_v4_fields_enabled';

    private const PASSWORD_KEY = 'collexia_password';
    private const CLIENT_SECRET_KEY = 'collexia_client_secret';

    /**
     * True since 2026-09-05: CollexiaClient::generateSignature() reproduces
     * Collexia's Postman pre-request script exactly (HMAC-SHA512 keyed by
     * Client Secret, Base64-encoded -- see tests/Unit/CollexiaClientTest.php,
     * all passing). Gates both isReadyToEnable() and the "Digital
     * Signature: Configured" status line.
     */
    public const SIGNING_IMPLEMENTED = true;

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

    /** Blank $plaintext leaves the stored secret untouched -- "leave blank to keep, type to replace". */
    public function setEncrypted(string $key, ?string $plaintext, ?int $userId): void
    {
        if ($plaintext === null || trim($plaintext) === '') {
            return;
        }
        $this->set($key, Encryption::encrypt($plaintext), $userId);
    }

    /** For actual API use once wired in -- never for display. */
    public function getDecrypted(string $key): ?string
    {
        $stored = $this->get($key);
        return $stored === '' ? null : Encryption::decrypt($stored);
    }

    /**
     * The single Collexia-provisioned frontEndUserName used for every
     * mandate action for now (Configuration sheet, Desert Ledger Test
     * Pack V4.0, 2026-10-12: "Front-end username: willemk") -- not in
     * BASE_FIELDS, so leaving it blank never blocks enabling EnDO; see
     * DebitOrderCollexiaController::frontEndUserName() for the fallback to
     * the logged-in staff member's own username when unset. Once Collexia
     * provisions individual front-end usernames per employee, this becomes
     * the shared default rather than the only value.
     */
    public function frontEndUsername(): string
    {
        return $this->get(self::FRONT_END_USERNAME_KEY);
    }

    /**
     * Toggle, not a cutover: the V4 mandate-load additions (dateOfBirth/
     * gender/debtorTelephone -- DebitOrderCollexiaController::
     * mandateDebtorExtraFields()) are only actually SENT when this is on.
     * Defaults off -- 2026-10-16 sandbox live-fire confirmed this specific
     * Collexia environment rejects dateOfBirth outright ("9404 Unexpected
     * field in input"), i.e. it isn't actually running V4 validation yet
     * despite accepting real connections, so sending these fields broke
     * EVERY mandate placement for a borrower with a date_of_birth on file,
     * split or not. Flip this on per-environment only once Collexia
     * confirms that environment genuinely accepts them -- falls back
     * cleanly to the plain V3 mandate body when off, same as before these
     * fields existed at all.
     */
    public function v4FieldsEnabled(): bool
    {
        return $this->get(self::V4_FIELDS_KEY) === 'on';
    }

    /**
     * EnCr (loan payout) values, kept beside the EnDO ones because they share the
     * same credentials and host. These are what Collexia provisions for the
     * LENDER's own paying account: uacId and the from-account details.
     */
    public const ENCR_REQUIRED_FIELDS = [
        'collexia_encr_uac_id' => 'EnCr User Access ID (uacId)',
        'collexia_encr_from_account_no' => 'Paying account number',
        'collexia_encr_from_ban_id' => 'Paying account bank',
        'collexia_encr_from_account_type' => 'Paying account type',
    ];

    private const ENCR_ENABLED_KEY = 'collexia_encr_enabled';

    /**
     * Toggle, not a cutover: with this off the Release form offers no EnCr option
     * and a loan is paid out exactly as it always was. Defaults off. Re-checks the
     * whole configuration live (like isEnabled()), so a setting that was blanked
     * after being switched on stops EnCr rather than sending a half-built request.
     */
    public function encrEnabled(): bool
    {
        return $this->get(self::ENCR_ENABLED_KEY) === 'on' && $this->isEnabled() && empty($this->missingForEncr());
    }

    public function encrSwitchedOn(): bool
    {
        return $this->get(self::ENCR_ENABLED_KEY) === 'on';
    }

    /** Labels of whatever EnCr still needs, on top of the EnDO configuration it shares. */
    public function missingForEncr(): array
    {
        $all = $this->allSettings();
        $missing = [];
        foreach (self::ENCR_REQUIRED_FIELDS as $key => $label) {
            if (trim((string) ($all[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** Collexia's optional fromGlobalId; null when not provisioned. */
    public function encrFromGlobalId(): ?int
    {
        $v = trim($this->get('collexia_encr_from_global_id'));
        return $v === '' ? null : (int) $v;
    }

    /** transferAlreadyAuthed: true unless explicitly switched off. */
    public function encrTransferAlreadyAuthed(): bool
    {
        return $this->get('collexia_encr_transfer_already_authed', 'on') !== 'off';
    }

    /**
     * Cancel, automatically and right after a manual payment is recorded, the Collexia collection that payment made unnecessary
     * (see MandateCollectionAutoStop). On unless explicitly switched off -- the kill switch if Collexia ever behaves unexpectedly;
     * with it off the red notice and its "Stop this collection" button work exactly as before.
     */
    public function autoStopCollections(): bool
    {
        return $this->get('collexia_auto_stop_collections', 'on') !== 'off';
    }

    public function isPasswordSet(): bool
    {
        return $this->get(self::PASSWORD_KEY) !== '';
    }

    public function isClientSecretSet(): bool
    {
        return $this->get(self::CLIENT_SECRET_KEY) !== '';
    }

    public function isEnabled(): bool
    {
        // Re-checks readiness live (not just the stored flag), so the API
        // client stays blocked even if 'on' and the config ever drifted
        // apart (e.g. a direct DB edit).
        return $this->get('collexia_enabled') === 'on' && $this->isReadyToEnable();
    }

    public function isConfigured(): bool
    {
        return empty($this->missingBaseFields($this->allSettings()));
    }

    public function isReadyToEnable(): bool
    {
        return $this->isConfigured() && $this->isPasswordSet() && $this->isClientSecretSet() && self::SIGNING_IMPLEMENTED;
    }

    /** Labels of whatever's still missing -- drives both the status indicator and the enable rejection message. */
    public function missingForEnable(): array
    {
        $missing = $this->missingBaseFields($this->allSettings());
        if (!$this->isPasswordSet()) {
            $missing[] = 'Password';
        }
        if (!$this->isClientSecretSet()) {
            $missing[] = 'Client Secret';
        }
        if (!self::SIGNING_IMPLEMENTED) {
            $missing[] = 'Digital Signature (HMAC-SHA512) implementation';
        }
        return $missing;
    }

    private function missingBaseFields(array $all): array
    {
        $missing = [];
        foreach (self::BASE_FIELDS as $key => $label) {
            if (trim((string) ($all[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /**
     * Not Configured / Partially Configured / Awaiting Security
     * Configuration / Ready for UAT / Enabled / Disabled.
     */
    public function status(): string
    {
        $all = $this->allSettings();
        $filledBase = count(self::BASE_FIELDS) - count($this->missingBaseFields($all));

        if ($filledBase === 0) {
            return 'Not Configured';
        }
        if ($filledBase < count(self::BASE_FIELDS)) {
            return 'Partially Configured';
        }
        if (!$this->isPasswordSet() || !$this->isClientSecretSet() || !self::SIGNING_IMPLEMENTED) {
            return 'Awaiting Security Configuration';
        }

        if (($all['collexia_enabled'] ?? 'off') === 'on') {
            return 'Enabled';
        }

        return ($all['collexia_enabled_reason'] ?? '') === 'disabled_by_user' ? 'Disabled' : 'Ready for UAT';
    }

    /**
     * Collexia rejects a single mandate above some maximum amount with
     * "10569 Mandate amount limit exceeded" -- confirmed by direct evidence
     * (debit order #36: N$548.33 registered fine, twice; N$1,096.66 was
     * rejected with this exact code). Collexia has since confirmed
     * (2026-09-15, via their product contact) this limit is a
     * sandbox/UAT-only restriction and will not apply once live in
     * production. This is a locally-configured, deliberately conservative
     * safety limit for testing against the UAT environment, set
     * explicitly in Settings -- there is NO default limit any more (changed
     * 2026-10-19: a default of N$1,000 silently blocked real production
     * loans with larger installments, since an unsaved or migrated-in value
     * kept applying a UAT-only restriction). Returns null when unset or
     * blank (no local limit enforced).
     */
    public function maxSingleMandateAmount(): ?float
    {
        $all = $this->allSettings();
        // Row never saved at all yet (fresh install/migration default) vs.
        // a row that exists but was deliberately blanked in Settings to
        // disable the check -- array_key_exists is true either way once
        // the migration/first save has run, even if the stored value is
        // NULL, so a genuine blank correctly returns null here instead of
        // silently falling back to the default.
        if (!array_key_exists('collexia_max_single_mandate_amount', $all)) {
            return null;
        }
        $value = trim((string) $all['collexia_max_single_mandate_amount']);
        return $value === '' ? null : (float) $value;
    }
}
