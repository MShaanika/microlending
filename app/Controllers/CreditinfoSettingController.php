<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CreditinfoDiagnosticLog;
use App\Models\CreditinfoSetting;
use App\Services\CreditinfoApiException;
use App\Services\CreditinfoClient;
use App\Services\CreditinfoUatReadinessService;

class CreditinfoSettingController extends Controller
{
    /** Plain (non-secret) fields the manage form can write. */
    private const KEYS = [
        'creditinfo_environment',
        'creditinfo_auth_url',
        'creditinfo_api_base_url',
        'creditinfo_api_version',
        'creditinfo_client_id',
        'creditinfo_scope',
        'creditinfo_inquiry_reason_search',
        'creditinfo_inquiry_reason_report',
        'creditinfo_gender_code_male',
        'creditinfo_gender_code_female',
    ];

    private CreditinfoSetting $settings;

    public function __construct()
    {
        $this->settings = new CreditinfoSetting();
    }

    /** Read-only integration status -- no credential values, no editable fields. */
    public function edit(): void
    {
        Auth::authorize('applications.screen');
        $readiness = new CreditinfoUatReadinessService();
        $this->view('creditinfo/settings/edit', [
            'title' => 'Credit Bureau API Settings',
            'status' => $this->settings->status(),
            'enabled' => $this->settings->isEnabled(),
            'settings' => $this->settings->allSettings(),
            'canManage' => Auth::can('admin.system_settings'),
            'checklist' => $readiness->checklist(),
            'isUatReady' => $readiness->isUatReady(),
            'blockers' => $readiness->blockers(),
            'recentDiagnostics' => (new CreditinfoDiagnosticLog())->recent(15),
        ]);
    }

    /** The actual editable credential form -- restricted to a higher permission than day-to-day screening staff. */
    public function manage(): void
    {
        Auth::authorize('admin.system_settings');
        $this->view('creditinfo/settings/manage', [
            'title' => 'Manage Creditinfo Credentials',
            'settings' => $this->settings->allSettings(),
            'enabled' => $this->settings->isEnabled(),
            'status' => $this->settings->status(),
            'missingForEnable' => $this->settings->missingForEnable(),
            'clientSecretSet' => $this->settings->isClientSecretSet(),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/settings/manage');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $wasEnabled = $this->settings->get('creditinfo_enabled') === 'on';

        // Only a field actually present in the submitted form is touched --
        // same array_key_exists convention as CollexiaSettingController.
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $_POST)) {
                $this->settings->set($key, trim((string) $_POST[$key]) ?: null, $userId);
            }
        }

        // Blank means "leave the stored secret as it is" -- see
        // CreditinfoSetting::setEncrypted(). Never logged, audited, or
        // echoed back.
        $this->settings->setEncrypted('creditinfo_client_secret', $_POST['creditinfo_client_secret'] ?? '', $userId);

        $wantsEnabled = !empty($_POST['creditinfo_enabled']);

        if ($wantsEnabled) {
            $missing = $this->settings->missingForEnable();
            if (!empty($missing)) {
                // Server-side guard: the toggle can never be persisted as
                // "on" while required configuration is missing, regardless
                // of what the front-end checkbox showed.
                $this->settings->set('creditinfo_enabled', 'off', $userId);
                Audit::log('Update', 'Creditinfo', 'Updated Creditinfo settings (enable blocked -- incomplete configuration)');
                Session::flash('error', 'Could not enable Creditinfo -- still missing: ' . implode(', ', $missing) . '.');
                $this->redirect('/creditinfo/settings/manage');
                return;
            }
            $this->settings->set('creditinfo_enabled', 'on', $userId);
            $this->settings->set('creditinfo_enabled_reason', null, $userId);
        } else {
            $this->settings->set('creditinfo_enabled', 'off', $userId);
            if ($wasEnabled) {
                $this->settings->set('creditinfo_enabled_reason', 'disabled_by_user', $userId);
            }
        }

        Audit::log('Update', 'Creditinfo', 'Updated Creditinfo settings');
        Session::flash('success', 'Creditinfo settings saved.');
        $this->redirect('/creditinfo/settings/manage');
    }

    /**
     * Live call to the UAT token endpoint ONLY -- proves credentials work
     * without running a credit check. Modeled directly on
     * AiSettingController::test(). Never flashes the token itself.
     */
    public function test(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/settings/manage');
            return;
        }

        try {
            $client = new CreditinfoClient();
            $client->getAccessToken('settings_test');
            $userId = Auth::user()['id'] ?? null;
            $this->settings->set('creditinfo_last_successful_connection_at', date('Y-m-d H:i:s'), $userId);
            Audit::log('Update', 'Creditinfo', 'Tested Creditinfo connection -- success');
            Session::flash('success', 'Connection successful -- a valid access token was issued.');
        } catch (CreditinfoApiException $e) {
            Audit::log('Update', 'Creditinfo', 'Tested Creditinfo connection -- failed');
            Session::flash('error', 'Could not connect to Creditinfo: ' . $e->getMessage());
        }

        $this->redirect('/creditinfo/settings/manage');
    }
}
