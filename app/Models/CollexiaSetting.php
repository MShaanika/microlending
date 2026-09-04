<?php

namespace App\Models;

use App\Core\Encryption;
use App\Core\Model;

/**
 * Credentials for the Collexia EnDO V3 REST API, editable via
 * /collexia/settings. Plain-text fields (base_url, the GIDs, the two
 * usernames) are stored as-is, same as before; the Authentication
 * Credential and Digital Signature secret are stored encrypted (see
 * App\Core\Encryption) and are never returned to a view in plain text --
 * only isCredentialSet()/isSignatureSet() (presence, not value) and the
 * masked getMaskedCredential()/getMaskedSignature() are.
 */
class CollexiaSetting extends Model
{
    /** The values every request to Collexia needs -- see CollexiaEndoApiClient. */
    private const BASE_FIELDS = [
        'collexia_base_url',
        'collexia_merchant_gid',
        'collexia_remote_gid',
        'collexia_system_username',
        'collexia_front_end_username',
    ];

    private const CREDENTIAL_KEY = 'collexia_credential';
    private const SIGNATURE_KEY = 'collexia_digital_signature_secret';

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

    /**
     * Encrypts and stores $plaintext under $key -- unless $plaintext is
     * null/empty, in which case the existing stored value (if any) is left
     * untouched. This is what makes the settings form's credential field a
     * "leave blank to keep, type a new value to replace" control rather
     * than something that could blank out a saved secret just by
     * re-submitting the form without re-entering it.
     */
    public function setEncrypted(string $key, ?string $plaintext, ?int $userId): void
    {
        if ($plaintext === null || trim($plaintext) === '') {
            return;
        }
        $this->set($key, Encryption::encrypt($plaintext), $userId);
    }

    /** Decrypts a stored secret for actual use (e.g. by the API client once signing is implemented) -- never for display. */
    public function getDecrypted(string $key): ?string
    {
        $stored = $this->get($key);
        return $stored === '' ? null : Encryption::decrypt($stored);
    }

    public function isCredentialSet(): bool
    {
        return $this->get(self::CREDENTIAL_KEY) !== '';
    }

    public function isSignatureSet(): bool
    {
        return $this->get(self::SIGNATURE_KEY) !== '';
    }

    public function isEnabled(): bool
    {
        // Belt-and-braces: the controller never lets 'on' get written
        // unless isReadyToEnable() was already true at save time, but this
        // re-checks live too, so the API client stays blocked even if the
        // stored flag and the underlying config were ever to drift apart
        // (e.g. a direct DB edit outside this app).
        return $this->get('collexia_enabled') === 'on' && $this->isReadyToEnable();
    }

    /** True once every field the API client needs to make a call has a value -- same definition as before this change. */
    public function isConfigured(): bool
    {
        return empty($this->missingBaseFields($this->allSettings()));
    }

    /** Base fields required before the toggle may be switched on -- credential too, once Collexia confirms auth is mandatory, this system already requires it before "Enabled". */
    public function isReadyToEnable(): bool
    {
        return $this->isConfigured() && $this->isCredentialSet();
    }

    /**
     * Human-readable labels for whatever's still missing before EnDO could
     * be switched on -- used both for the status indicator and for the
     * server-side "you can't enable this yet" rejection message.
     */
    public function missingForEnable(): array
    {
        $missing = $this->missingBaseFields($this->allSettings());
        if (!$this->isCredentialSet()) {
            $missing[] = 'Authentication Credential / Password';
        }
        return $missing;
    }

    private function missingBaseFields(array $all): array
    {
        $labels = [
            'collexia_base_url' => 'Host / Base URL',
            'collexia_merchant_gid' => 'Merchant GID',
            'collexia_remote_gid' => 'Remote GID',
            'collexia_system_username' => 'System Username',
            'collexia_front_end_username' => 'Front-End Username',
        ];
        $missing = [];
        foreach (self::BASE_FIELDS as $key) {
            if (trim((string) ($all[$key] ?? '')) === '') {
                $missing[] = $labels[$key];
            }
        }
        return $missing;
    }

    /**
     * One of: Not Configured, Partially Configured, Awaiting Security
     * Configuration, Ready for UAT, Enabled, Disabled.
     *
     * "Ready for UAT" and "Enabled" both require the Authentication
     * Credential (mandatory before the toggle can go on -- see
     * isReadyToEnable()); neither requires the Digital Signature secret,
     * since Collexia has not yet supplied that specification and this
     * system deliberately does not invent one. "Disabled" is shown only
     * once the toggle itself has actually been switched off after being
     * configured -- e.g. an integration someone paused -- not the same
     * moment as "Ready for UAT" (never yet turned on). This app doesn't
     * keep a history table, so it approximates that distinction using
     * whichever explicit reason CollexiaSettingController last wrote to
     * 'collexia_enabled_reason' (see update()) rather than guessing.
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
        if (!$this->isCredentialSet()) {
            return 'Awaiting Security Configuration';
        }

        if (($all['collexia_enabled'] ?? 'off') === 'on') {
            return 'Enabled';
        }

        return ($all['collexia_enabled_reason'] ?? '') === 'disabled_by_user' ? 'Disabled' : 'Ready for UAT';
    }
}
