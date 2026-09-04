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
    private const KEYS = [
        'collexia_base_url',
        'collexia_merchant_gid',
        'collexia_remote_gid',
        'collexia_system_username',
        'collexia_front_end_username',
    ];

    private CollexiaSetting $settings;

    public function __construct()
    {
        $this->settings = new CollexiaSetting();
    }

    public function edit(): void
    {
        Auth::authorize('collections.debit_orders');
        $this->view('collexia/settings/edit', [
            'title' => 'Debit Order API Settings',
            'settings' => $this->settings->allSettings(),
            'enabled' => $this->settings->isEnabled(),
            'configured' => $this->settings->isConfigured(),
            'status' => $this->settings->status(),
            'missingForEnable' => $this->settings->missingForEnable(),
            'credentialSet' => $this->settings->isCredentialSet(),
            'signatureSet' => $this->settings->isSignatureSet(),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collexia/settings');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $wasEnabled = $this->settings->get('collexia_enabled') === 'on';

        foreach (self::KEYS as $key) {
            $this->settings->set($key, trim($_POST[$key] ?? '') ?: null, $userId);
        }

        // Blank means "leave the stored secret as it is" -- see
        // CollexiaSetting::setEncrypted(). Neither value is ever logged,
        // audited, or echoed back; only their presence (isCredentialSet()/
        // isSignatureSet()) is used anywhere else in this app.
        $this->settings->setEncrypted('collexia_credential', $_POST['collexia_credential'] ?? '', $userId);
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
                $this->redirect('/collexia/settings');
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
        $this->redirect('/collexia/settings');
    }
}
