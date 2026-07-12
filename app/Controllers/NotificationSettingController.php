<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\NotificationSetting;
use App\Services\EmailSenderService;
use App\Services\SmsSenderService;

class NotificationSettingController extends Controller
{
    private NotificationSetting $settings;

    /** Secret fields are never re-populated into the HTML -- the view shows
     *  a masked placeholder, and a blank submission leaves the stored value
     *  untouched rather than overwriting it with an empty string. */
    private const SECRET_KEYS = ['SMTP_PASSWORD', 'TWILIO_AUTH_TOKEN'];

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
            'TWILIO_FROM_NUMBER' => trim($_POST['twilio_from_number'] ?? ''),
        ];

        foreach ($fields as $key => $value) {
            $this->settings->set($key, $value, 'SMS', $userId);
        }

        $token = trim($_POST['twilio_auth_token'] ?? '');
        if ($token !== '') {
            $this->settings->set('TWILIO_AUTH_TOKEN', $token, 'SMS', $userId);
        }

        Audit::log('Update', 'Notifications', 'Updated Twilio SMS settings');
        Session::flash('success', 'SMS (Twilio) settings saved.');
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

        $result = SmsSenderService::send($recipient, 'This is a test message from DesertLedger to confirm your Twilio settings are working.');

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

        $this->view('notifications/settings', [
            'title' => 'Notification Settings',
            'email' => $email,
            'sms' => $sms,
            'emailConfigured' => !empty($email['SMTP_HOST']),
            'smsConfigured' => !empty($sms['TWILIO_ACCOUNT_SID']) && !empty($sms['TWILIO_AUTH_TOKEN']),
        ]);
    }
}
