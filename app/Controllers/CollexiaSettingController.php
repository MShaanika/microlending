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

        foreach (self::KEYS as $key) {
            $this->settings->set($key, trim($_POST[$key] ?? '') ?: null, $userId);
        }
        $this->settings->set('collexia_enabled', !empty($_POST['collexia_enabled']) ? 'on' : 'off', $userId);

        Audit::log('Update', 'Debit Orders', 'Updated Collexia API settings');
        Session::flash('success', 'API settings saved.');
        $this->redirect('/collexia/settings');
    }
}
