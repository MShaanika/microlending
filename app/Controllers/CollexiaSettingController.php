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
    /** Plain (non-secret) fields the manage form can write -- collexia_front_end_username is deliberately absent: removed from the UI, never written, existing stored value (if any) untouched. */
    private const KEYS = [
        'collexia_base_url',
        'collexia_merchant_gid',
        'collexia_remote_gid',
        'collexia_system_username',
        'collexia_client_id',
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
            'signatureSet' => $this->settings->isSignatureSet(),
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
        // echoed back; only presence (isPasswordSet()/isClientSecretSet()/
        // isSignatureSet()) is used anywhere else in this app.
        $this->settings->setEncrypted('collexia_password', $_POST['collexia_password'] ?? '', $userId);
        $this->settings->setEncrypted('collexia_client_secret', $_POST['collexia_client_secret'] ?? '', $userId);
        $this->settings->setEncrypted('collexia_digital_signature_secret', $_POST['collexia_digital_signature_secret'] ?? '', $userId);

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
        Session::flash('success', 'API settings saved.');
        $this->redirect('/collexia/settings/manage');
    }
}
