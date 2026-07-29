<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmZoomSetting;

class HrmZoomSettingController extends Controller
{
    private HrmZoomSetting $settings;

    public function __construct()
    {
        $this->settings = new HrmZoomSetting();
    }

    public function edit(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/zoom-settings/edit', [
            'title' => 'Zoom Settings',
            'settings' => $this->settings->allSettings(),
        ]);
    }

    public function update(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/zoom-settings');
            return;
        }

        $enabled = !empty($_POST['zoom_enabled']) ? 'on' : 'off';
        $apiKey = trim($_POST['zoom_api_key'] ?? '');
        $apiSecret = trim($_POST['zoom_api_secret'] ?? '');
        $accountId = trim($_POST['zoom_account_id'] ?? '');

        if ($enabled === 'on' && ($apiKey === '' || $apiSecret === '' || $accountId === '')) {
            Session::flash('error', 'Account ID, Client ID, and Client Secret are required to enable Zoom integration.');
            $this->redirect('/hrm/zoom-settings');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $this->settings->set('zoom_enabled', $enabled, $userId);
        $this->settings->set('zoom_api_key', $apiKey ?: null, $userId);
        $this->settings->set('zoom_api_secret', $apiSecret ?: null, $userId);
        $this->settings->set('zoom_account_id', $accountId ?: null, $userId);

        Audit::log('Update', 'HRM', 'Updated Zoom meeting settings');
        Session::flash('success', 'Zoom settings saved.');
        $this->redirect('/hrm/zoom-settings');
    }
}
