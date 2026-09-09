<?php

namespace App\Models;

use App\Core\Encryption;
use App\Core\Model;

/**
 * Creditinfo Namibia CBS REST API settings, editable via /creditinfo/settings.
 * Same key-value shape as CollexiaSetting -- plain fields stored as-is,
 * Client Secret and the cached JWT are encrypted (App\Core\Encryption) and
 * never leave this class in plain text except getDecrypted() (for actual
 * API use, never for display).
 *
 * creditinfo_gender_code_male/_female and creditinfo_inquiry_reason_search/
 * _report are deliberately here, not hardcoded in the service layer -- see
 * CreditinfoBureauClient's docblock for why (neither is documented anywhere
 * in the supplied Creditinfo manual/Postman collection with full
 * confidence; both need to be correctable via a settings change, not a
 * deploy, once Creditinfo confirms them).
 */
class CreditinfoSetting extends Model
{
    private const BASE_FIELDS = [
        'creditinfo_environment' => 'Environment',
        'creditinfo_auth_url' => 'Authentication URL',
        'creditinfo_api_base_url' => 'API Base URL',
        'creditinfo_api_version' => 'API Version',
        'creditinfo_client_id' => 'Client ID',
        'creditinfo_scope' => 'Scope',
    ];

    private const CLIENT_SECRET_KEY = 'creditinfo_client_secret';
    private const CACHED_TOKEN_KEY = 'creditinfo_cached_access_token';
    private const CACHED_TOKEN_EXPIRES_KEY = 'creditinfo_cached_token_expires_at';

    public function get(string $key, string $default = ''): string
    {
        $value = $this->scalar("SELECT setting_value FROM creditinfo_settings WHERE setting_key = ?", [$key]);
        return $value !== false && $value !== null ? (string) $value : $default;
    }

    public function allSettings(): array
    {
        $rows = $this->query("SELECT setting_key, setting_value FROM creditinfo_settings")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    public function set(string $key, ?string $value, ?int $userId): void
    {
        $this->query(
            "INSERT INTO creditinfo_settings (setting_key, setting_value, updated_by)
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

    public function isClientSecretSet(): bool
    {
        return $this->get(self::CLIENT_SECRET_KEY) !== '';
    }

    public function isEnabled(): bool
    {
        return $this->get('creditinfo_enabled') === 'on' && $this->isReadyToEnable();
    }

    public function isConfigured(): bool
    {
        return empty($this->missingBaseFields($this->allSettings()));
    }

    public function isReadyToEnable(): bool
    {
        return $this->isConfigured() && $this->isClientSecretSet();
    }

    /** Labels of whatever's still missing -- drives both the status indicator and the enable rejection message. */
    public function missingForEnable(): array
    {
        $missing = $this->missingBaseFields($this->allSettings());
        if (!$this->isClientSecretSet()) {
            $missing[] = 'Client Secret';
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

    /** Not Configured / Partially Configured / Awaiting Security Configuration / Ready for UAT / Enabled / Disabled. */
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
        if (!$this->isClientSecretSet()) {
            return 'Awaiting Security Configuration';
        }

        if (($all['creditinfo_enabled'] ?? 'off') === 'on') {
            return 'Enabled';
        }

        return ($all['creditinfo_enabled_reason'] ?? '') === 'disabled_by_user' ? 'Disabled' : 'Ready for UAT';
    }

    /** Cached JWT if present and not expired within $safetyMarginSeconds of now -- else null, telling the caller to refresh. */
    public function cachedAccessToken(int $safetyMarginSeconds = 60): ?string
    {
        $expiresAt = $this->get(self::CACHED_TOKEN_EXPIRES_KEY);
        if ($expiresAt === '' || strtotime($expiresAt) === false) {
            return null;
        }
        if (strtotime($expiresAt) - time() <= $safetyMarginSeconds) {
            return null;
        }
        return $this->getDecrypted(self::CACHED_TOKEN_KEY);
    }

    public function storeAccessToken(string $token, int $expiresInSeconds, ?int $userId): void
    {
        $this->setEncrypted(self::CACHED_TOKEN_KEY, $token, $userId);
        $this->set(self::CACHED_TOKEN_EXPIRES_KEY, date('Y-m-d H:i:s', time() + $expiresInSeconds), $userId);
    }

    /** Both gender codes must be explicitly configured before a search can run -- see CreditinfoBureauClient. */
    public function genderCode(string $genderLabel): ?int
    {
        $key = $genderLabel === 'Male' ? 'creditinfo_gender_code_male' : ($genderLabel === 'Female' ? 'creditinfo_gender_code_female' : null);
        if ($key === null) {
            return null;
        }
        $value = $this->get($key);
        return $value === '' ? null : (int) $value;
    }
}
