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

    private const PASSWORD_KEY = 'collexia_password';
    private const CLIENT_SECRET_KEY = 'collexia_client_secret';
    private const SIGNATURE_KEY = 'collexia_digital_signature_secret';

    /**
     * Flip to true only once CollexiaClient::generateSignature() actually
     * reproduces Collexia's Postman pre-request script -- gates both
     * isReadyToEnable() and the "Digital Signature: Configured" status line
     * so neither claims the signing requirement is met before it is.
     */
    public const SIGNING_IMPLEMENTED = false;

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

    public function isPasswordSet(): bool
    {
        return $this->get(self::PASSWORD_KEY) !== '';
    }

    public function isClientSecretSet(): bool
    {
        return $this->get(self::CLIENT_SECRET_KEY) !== '';
    }

    public function isSignatureSet(): bool
    {
        return $this->get(self::SIGNATURE_KEY) !== '';
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
}
