<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\NotificationSetting;

class AiSettingController extends Controller
{
    private NotificationSetting $settings;

    public function __construct()
    {
        $this->settings = new NotificationSetting();
    }

    public function index(): void
    {
        Auth::authorize('admin.system_settings');

        $ai = [];
        foreach ($this->settings->allSettings('AI') as $row) {
            $ai[$row['setting_key']] = $row['setting_value'];
        }

        $this->view('settings/ai/index', [
            'title' => 'AI Settings',
            'ai' => $ai,
            'configured' => !empty($ai['OPENAI_API_KEY']),
        ]);
    }

    public function store(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/ai');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $model = trim($_POST['openai_model'] ?? '') ?: 'gpt-4o-mini';
        $this->settings->set('OPENAI_MODEL', $model, 'AI', $userId);

        $apiKey = trim($_POST['openai_api_key'] ?? '');
        if ($apiKey !== '') {
            $this->settings->set('OPENAI_API_KEY', $apiKey, 'AI', $userId);
        }

        Audit::log('Update', 'Settings', 'Updated AI (OpenAI) settings');
        Session::flash('success', 'AI settings saved.');
        $this->redirect('/settings/ai');
    }

    public function test(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/ai');
            return;
        }

        $apiKey = $this->settings->get('OPENAI_API_KEY');
        if ($apiKey === '') {
            Session::flash('error', 'Add an API key first, then test the connection.');
            $this->redirect('/settings/ai');
            return;
        }

        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            Session::flash('error', 'Could not reach OpenAI: ' . $curlError);
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            Session::flash('success', 'Connection successful -- the API key is valid.');
        } else {
            $data = json_decode($response, true);
            Session::flash('error', 'OpenAI rejected the request: ' . ($data['error']['message'] ?? ('HTTP ' . $httpCode)));
        }

        $this->redirect('/settings/ai');
    }
}
