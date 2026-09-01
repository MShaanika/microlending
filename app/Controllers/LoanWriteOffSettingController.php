<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\SystemSetting;

class LoanWriteOffSettingController extends Controller
{
    private SystemSetting $settings;

    public function __construct()
    {
        $this->settings = new SystemSetting();
    }

    public function index(): void
    {
        Auth::authorize('admin.system_settings');

        $this->view('settings/loan_write_off/index', [
            'title' => 'Write-Off Accounting Method',
            'method' => $this->settings->get('LOAN_WRITE_OFF_METHOD', 'SELECT_AT_WRITE_OFF'),
        ]);
    }

    public function store(): void
    {
        Auth::authorize('admin.system_settings');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/loan-write-off');
            return;
        }

        $method = trim((string) ($_POST['method'] ?? ''));
        if (!in_array($method, ['ALLOWANCE', 'DIRECT', 'SELECT_AT_WRITE_OFF'], true)) {
            Session::flash('error', 'Select a valid write-off accounting method.');
            $this->redirect('/settings/loan-write-off');
            return;
        }

        $this->settings->set('LOAN_WRITE_OFF_METHOD', $method, 'Accounting', Auth::user()['id'] ?? null);

        Audit::log('Update', 'Settings', 'Set Write-Off Accounting Method to ' . $method);
        Session::flash('success', 'Write-off accounting method saved.');
        $this->redirect('/settings/loan-write-off');
    }
}
