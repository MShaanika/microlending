<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CollexiaSetting;

class CollexiaSettingController extends Controller
{
    /** Plain (non-secret) fields the manage form can write. */
    private const KEYS = [
        'collexia_base_url',
        'collexia_merchant_gid',
        'collexia_remote_gid',
        'collexia_system_username',
        'collexia_client_id',
        'collexia_max_single_mandate_amount',
        'collexia_front_end_username',
        'collexia_encr_uac_id',
        'collexia_encr_from_account_no',
        'collexia_encr_from_ban_id',
        'collexia_encr_from_account_type',
        'collexia_encr_from_global_id',
    ];

    private CollexiaSetting $settings;

    public function __construct()
    {
        $this->settings = new CollexiaSetting();
    }

    /** Read-only integration status -- no credential values, no editable fields. Same base permission as before. */
    public function edit(): void
    {
        Auth::authorize('collections.debit_orders');
        $this->view('collexia/settings/edit', [
            'title' => 'Debit Order API Settings',
            'status' => $this->settings->status(),
            'enabled' => $this->settings->isEnabled(),
            'canManage' => Auth::can('admin.system_settings'),
        ]);
    }

    /** The actual editable credential form -- restricted to a higher permission than day-to-day debit order staff. */
    public function manage(): void
    {
        Auth::authorize('admin.system_settings');
        $this->view('collexia/settings/manage', [
            'title' => 'Manage Collexia EnDO Credentials',
            'settings' => $this->settings->allSettings(),
            'enabled' => $this->settings->isEnabled(),
            'status' => $this->settings->status(),
            'missingForEnable' => $this->settings->missingForEnable(),
            'passwordSet' => $this->settings->isPasswordSet(),
            'clientSecretSet' => $this->settings->isClientSecretSet(),
            'encrSwitchedOn' => $this->settings->encrSwitchedOn(),
            'encrMissing' => $this->settings->missingForEncr(),
            'encrSchemaReady' => \App\Services\CollexiaEncrDisbursementService::schemaReady(),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collexia/settings/manage');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $wasEnabled = $this->settings->get('collexia_enabled') === 'on';

        // Only a field actually present in the submitted form is touched --
        // a field that isn't rendered (e.g. a removed one) is never sent by
        // the browser at all, so array_key_exists correctly leaves its
        // stored value exactly as it was rather than nulling it out.
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $_POST)) {
                $this->settings->set($key, trim((string) $_POST[$key]) ?: null, $userId);
            }
        }

        // Blank means "leave the stored secret as it is" -- see
        // CollexiaSetting::setEncrypted(). Never logged, audited, or
        // echoed back; only presence (isPasswordSet()/isClientSecretSet())
        // is used anywhere else in this app. There is no separate Digital
        // Signature secret field: the script confirmed Client Secret
        // itself is the HMAC-SHA512 key (CollexiaClient::generateSignature()).
        $this->settings->setEncrypted('collexia_password', $_POST['collexia_password'] ?? '', $userId);
        $this->settings->setEncrypted('collexia_client_secret', $_POST['collexia_client_secret'] ?? '', $userId);

        // Same unchecked-checkbox-sends-nothing handling as collexia_enabled
        // above -- explicitly 'on'/'off', not left via the generic KEYS
        // loop, so unchecking actually turns it off rather than silently
        // doing nothing. See CollexiaSetting::v4FieldsEnabled()'s docblock
        // for why this defaults off and what flipping it on means.
        $this->settings->set('collexia_v4_fields_enabled', !empty($_POST['collexia_v4_fields_enabled']) ? 'on' : 'off', $userId);

        // Optional "transferAlreadyAuthed" flag and the EnCr payout switch -- same unchecked-sends-nothing handling.
        $this->settings->set('collexia_encr_transfer_already_authed', !empty($_POST['collexia_encr_transfer_already_authed']) ? 'on' : 'off', $userId);
        $encrNote = $this->saveEncrSwitch($userId);

        $wantsEnabled = !empty($_POST['collexia_enabled']);

        if ($wantsEnabled) {
            $missing = $this->settings->missingForEnable();
            if (!empty($missing)) {
                // Server-side guard: the toggle can never be persisted as
                // "on" while required configuration is missing, regardless
                // of what the front-end checkbox showed. Everything else
                // submitted on this form is still saved.
                $this->settings->set('collexia_enabled', 'off', $userId);
                Audit::log('Update', 'Debit Orders', 'Updated Collexia API settings (enable blocked -- incomplete configuration)');
                Session::flash('error', 'Could not enable EnDO -- still missing: ' . implode(', ', $missing) . '.');
                $this->redirect('/collexia/settings/manage');
                return;
            }
            $this->settings->set('collexia_enabled', 'on', $userId);
            $this->settings->set('collexia_enabled_reason', null, $userId);
        } else {
            $this->settings->set('collexia_enabled', 'off', $userId);
            // Distinguishes "switched off by a user" from "never turned on
            // yet" for the status indicator (Disabled vs. Ready for UAT) --
            // see CollexiaSetting::status(). Only counts as the former if
            // it was actually on before this save.
            if ($wasEnabled) {
                $this->settings->set('collexia_enabled_reason', 'disabled_by_user', $userId);
            }
        }

        Audit::log('Update', 'Debit Orders', 'Updated Collexia API settings');
        if ($encrNote !== null) {
            Session::flash('error', 'API settings saved, but ' . $encrNote);
        } else {
            Session::flash('success', 'API settings saved.');
        }
        $this->redirect('/collexia/settings/manage');
    }

    /**
     * The EnCr payout switch. Switching it OFF always works. Switching it ON is refused
     * (and the switch stored as off) unless the EnCr values are complete and the database
     * columns exist -- never persisted "on" for a half-configured payout. Returns the reason
     * it could not be switched on, or null.
     */
    private function saveEncrSwitch(?int $userId): ?string
    {
        $wasOn = $this->settings->encrSwitchedOn();

        if (empty($_POST['collexia_encr_enabled'])) {
            $this->settings->set('collexia_encr_enabled', 'off', $userId);
            if ($wasOn) {
                Audit::log('Update', 'Loans', 'Switched Collexia EnCr loan payouts OFF');
            }
            return null;
        }

        $missing = $this->settings->missingForEncr();
        if (!\App\Services\CollexiaEncrDisbursementService::schemaReady()) {
            $missing[] = 'database update (database/collexia_encr_disbursement.sql has not been applied)';
        }
        if (!empty($missing)) {
            $this->settings->set('collexia_encr_enabled', 'off', $userId);
            return 'EnCr payouts were NOT switched on -- still missing: ' . implode(', ', $missing) . '.';
        }

        $this->settings->set('collexia_encr_enabled', 'on', $userId);
        if (!$wasOn) {
            Audit::log('Update', 'Loans', 'Switched Collexia EnCr loan payouts ON');
        }
        return null;
    }
}
