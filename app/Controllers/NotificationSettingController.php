<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\NotificationSetting;
use App\Services\BlandVoiceCallService;
use App\Services\CollectionsAiCallService;
use App\Services\EmailSenderService;
use App\Services\RetellVoiceCallService;
use App\Services\SmsSenderService;

class NotificationSettingController extends Controller
{
    private NotificationSetting $settings;

    /** Secret fields are never re-populated into the HTML -- the view shows
     *  a masked placeholder, and a blank submission leaves the stored value
     *  untouched rather than overwriting it with an empty string. */
    private const SECRET_KEYS = ['SMTP_PASSWORD', 'TWILIO_AUTH_TOKEN', 'BLAND_API_KEY', 'RETELL_API_KEY'];

    public function __construct()
    {
        $this->settings = new NotificationSetting();
    }

    public function index(): void
    {
        Auth::authorize('notifications.settings');
        $this->renderIndex();
    }

    public function storeEmailSettings(): void
    {
        Auth::authorize('notifications.settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/settings');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $fields = [
            'SMTP_HOST' => trim($_POST['smtp_host'] ?? ''),
            'SMTP_PORT' => trim($_POST['smtp_port'] ?? ''),
            'SMTP_ENCRYPTION' => in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls',
            'SMTP_USERNAME' => trim($_POST['smtp_username'] ?? ''),
            'SMTP_FROM_EMAIL' => trim($_POST['smtp_from_email'] ?? ''),
            'SMTP_FROM_NAME' => trim($_POST['smtp_from_name'] ?? ''),
        ];

        foreach ($fields as $key => $value) {
            $this->settings->set($key, $value, 'Email', $userId);
        }

        $password = trim($_POST['smtp_password'] ?? '');
        if ($password !== '') {
            $this->settings->set('SMTP_PASSWORD', $password, 'Email', $userId);
        }

        Audit::log('Update', 'Notifications', 'Updated SMTP email settings');
        Session::flash('success', 'Email (SMTP) settings saved.');
        $this->redirect('/notifications/settings');
    }

    public function storeSmsSettings(): void
    {
        Auth::authorize('notifications.settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/settings');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $fields = [
            'TWILIO_ACCOUNT_SID' => trim($_POST['twilio_account_sid'] ?? ''),
            'TWILIO_MESSAGING_SERVICE_SID' => trim($_POST['twilio_messaging_service_sid'] ?? ''),
        ];

        foreach ($fields as $key => $value) {
            $this->settings->set($key, $value, 'SMS', $userId);
        }

        $token = trim($_POST['twilio_auth_token'] ?? '');
        if ($token !== '') {
            $this->settings->set('TWILIO_AUTH_TOKEN', $token, 'SMS', $userId);
        }

        Audit::log('Update', 'Notifications', 'Updated Twilio SMS settings');
        Session::flash('success', 'SMS settings saved.');
        $this->redirect('/notifications/settings');
    }

    public function testEmail(): void
    {
        Auth::authorize('notifications.settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/settings');
            return;
        }

        $recipient = trim($_POST['test_recipient'] ?? '');
        if ($recipient === '') {
            Session::flash('error', 'Enter a recipient email address to send a test message.');
            $this->redirect('/notifications/settings');
            return;
        }

        $result = EmailSenderService::send($recipient, 'DesertLedger Test Email', 'This is a test message from DesertLedger to confirm your SMTP settings are working.');

        if ($result['success']) {
            Session::flash('success', 'Test email sent to ' . $recipient . '.');
        } else {
            Session::flash('error', 'Test email failed: ' . $result['error']);
        }

        $this->redirect('/notifications/settings');
    }

    public function storeVoiceSettings(): void
    {
        Auth::authorize('notifications.settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/settings');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $fields = [
            'AI_VOICE_ENABLED' => isset($_POST['ai_voice_enabled']) ? '1' : '0',
            'AI_VOICE_PROVIDER' => in_array($_POST['ai_voice_provider'] ?? '', ['bland', 'retell'], true) ? $_POST['ai_voice_provider'] : 'bland',
            'BLAND_VOICE' => trim($_POST['bland_voice'] ?? '') ?: 'maya',
            'BLAND_FROM_NUMBER' => trim($_POST['bland_from_number'] ?? ''),
            'AI_VOICE_MAX_DURATION_MINUTES' => (string) max(1, (int) ($_POST['ai_voice_max_duration_minutes'] ?? 5)),
            'AI_VOICE_CITATION_SCHEMA_ID' => trim($_POST['ai_voice_citation_schema_id'] ?? ''),
            'AI_VOICE_SCRIPT' => trim($_POST['ai_voice_script'] ?? ''),
            'RETELL_AGENT_ID' => trim($_POST['retell_agent_id'] ?? ''),
            'RETELL_FROM_NUMBER' => trim($_POST['retell_from_number'] ?? ''),
        ];

        foreach ($fields as $key => $value) {
            $this->settings->set($key, $value, 'AI', $userId);
        }

        $apiKey = trim($_POST['bland_api_key'] ?? '');
        if ($apiKey !== '') {
            $this->settings->set('BLAND_API_KEY', $apiKey, 'AI', $userId);
        }

        $retellApiKey = trim($_POST['retell_api_key'] ?? '');
        if ($retellApiKey !== '') {
            $this->settings->set('RETELL_API_KEY', $retellApiKey, 'AI', $userId);
        }

        Audit::log('Update', 'Notifications', 'Updated AI voice call settings');
        Session::flash('success', 'AI voice call settings saved.');
        $this->redirect('/notifications/settings');
    }

    public function testCall(): void
    {
        Auth::authorize('notifications.settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/settings');
            return;
        }

        $recipient = trim($_POST['test_recipient'] ?? '');
        if ($recipient === '') {
            Session::flash('error', 'Enter a phone number to place a test call.');
            $this->redirect('/notifications/settings');
            return;
        }

        $provider = trim((string) $this->settings->get('AI_VOICE_PROVIDER')) ?: 'bland';

        if ($provider === 'retell') {
            $result = RetellVoiceCallService::dispatch($recipient, [
                'borrower_name' => 'Test Contact',
                'loan_no' => 'TEST',
                'days_in_arrears' => '0',
                'outstanding_balance' => 'N$0.00',
                'company_name' => 'DesertLedger',
            ]);
        } else {
            $script = trim((string) $this->settings->get('AI_VOICE_SCRIPT'))
                ?: 'This is a short test call from DesertLedger to confirm your voice call settings are working. Say hello, then end the call politely.';
            $result = BlandVoiceCallService::dispatch($recipient, $script, full_url('/api/voice-calls/webhook/' . CollectionsAiCallService::webhookToken()));
        }

        if ($result['success']) {
            Session::flash('success', 'Test call placed to ' . $recipient . '.');
        } else {
            Session::flash('error', 'Test call failed: ' . $result['error']);
        }

        $this->redirect('/notifications/settings');
    }

    public function testSms(): void
    {
        Auth::authorize('notifications.settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/settings');
            return;
        }

        $recipient = trim($_POST['test_recipient'] ?? '');
        if ($recipient === '') {
            Session::flash('error', 'Enter a recipient phone number to send a test message.');
            $this->redirect('/notifications/settings');
            return;
        }

        $result = SmsSenderService::send($recipient, 'This is a test message from DesertLedger to confirm your SMS settings are working.');

        if ($result['success']) {
            Session::flash('success', 'Test SMS sent to ' . $recipient . '.');
        } else {
            Session::flash('error', 'Test SMS failed: ' . $result['error']);
        }

        $this->redirect('/notifications/settings');
    }

    private function renderIndex(): void
    {
        $email = [];
        foreach ($this->settings->allSettings('Email') as $row) {
            $email[$row['setting_key']] = $row['setting_value'];
        }

        $sms = [];
        foreach ($this->settings->allSettings('SMS') as $row) {
            $sms[$row['setting_key']] = $row['setting_value'];
        }

        $voice = [];
        foreach ($this->settings->allSettings('AI') as $row) {
            $voice[$row['setting_key']] = $row['setting_value'];
        }
        $voiceProvider = $voice['AI_VOICE_PROVIDER'] ?? 'bland';
        $voiceConfigured = $voiceProvider === 'retell'
            ? (!empty($voice['RETELL_API_KEY']) && !empty($voice['RETELL_AGENT_ID']) && !empty($voice['RETELL_FROM_NUMBER']))
            : !empty($voice['BLAND_API_KEY']);

        $this->view('notifications/settings', [
            'title' => 'Notification Settings',
            'email' => $email,
            'sms' => $sms,
            'voice' => $voice,
            'emailConfigured' => !empty($email['SMTP_HOST']),
            'smsConfigured' => !empty($sms['TWILIO_ACCOUNT_SID']) && !empty($sms['TWILIO_AUTH_TOKEN']) && !empty($sms['TWILIO_MESSAGING_SERVICE_SID']),
            'voiceConfigured' => $voiceConfigured,
            'voiceWebhookUrl' => full_url('/api/voice-calls/webhook/' . CollectionsAiCallService::webhookToken()),
        ]);
    }
}
