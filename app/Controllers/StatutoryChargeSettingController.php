<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\StatutoryCharge;

class StatutoryChargeSettingController extends Controller
{
    private StatutoryCharge $charges;

    public function __construct()
    {
        $this->charges = new StatutoryCharge();
    }

    public function index(): void
    {
        Auth::authorize('compliance.view');

        $this->view('compliance/settings', [
            'title' => 'Statutory Charge Settings',
            'namfisaSettings' => $this->charges->allNamfisaSettings(),
            'dutyStampSettings' => $this->charges->allDutyStampSettings(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function storeNamfisaSetting(): void
    {
        Auth::authorize('compliance.namfisa');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/compliance/settings');
            return;
        }

        $rate = (float) ($_POST['levy_rate'] ?? -1);
        $effectiveFrom = trim($_POST['effective_from'] ?? '');
        $errors = [];

        if ($rate < 0) {
            $errors[] = 'Levy rate must be zero or more.';
        }
        if ($effectiveFrom === '' || strtotime($effectiveFrom) === false) {
            $errors[] = 'A valid effective date is required.';
        }

        if (!empty($errors)) {
            $this->view('compliance/settings', [
                'title' => 'Statutory Charge Settings',
                'namfisaSettings' => $this->charges->allNamfisaSettings(),
                'dutyStampSettings' => $this->charges->allDutyStampSettings(),
                'errors' => ['namfisa' => $errors],
                'old' => $_POST,
            ]);
            return;
        }

        $this->charges->createNamfisaSetting([
            'levy_name' => trim($_POST['levy_name'] ?? '') ?: 'NAMFISA Levy',
            'levy_rate' => $rate,
            'calculation_basis' => $_POST['calculation_basis'] ?? 'Principal Amount',
            'effective_from' => $effectiveFrom,
            'is_active' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Compliance', 'Added new NAMFISA levy rate (' . $rate . '%) effective ' . $effectiveFrom);
        Session::flash('success', 'New NAMFISA levy rate saved.');
        $this->redirect('/compliance/settings');
    }

    public function storeDutyStampSetting(): void
    {
        Auth::authorize('compliance.duty_stamp');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/compliance/settings');
            return;
        }

        $amount = (float) ($_POST['stamp_amount'] ?? -1);
        $effectiveFrom = trim($_POST['effective_from'] ?? '');
        $errors = [];

        if ($amount < 0) {
            $errors[] = 'Stamp amount must be zero or more.';
        }
        if ($effectiveFrom === '' || strtotime($effectiveFrom) === false) {
            $errors[] = 'A valid effective date is required.';
        }

        if (!empty($errors)) {
            $this->view('compliance/settings', [
                'title' => 'Statutory Charge Settings',
                'namfisaSettings' => $this->charges->allNamfisaSettings(),
                'dutyStampSettings' => $this->charges->allDutyStampSettings(),
                'errors' => ['dutyStamp' => $errors],
                'old' => $_POST,
            ]);
            return;
        }

        $this->charges->createDutyStampSetting([
            'stamp_name' => trim($_POST['stamp_name'] ?? '') ?: 'Duty Stamp',
            'stamp_amount' => $amount,
            'calculation_type' => $_POST['calculation_type'] ?? 'Fixed',
            'calculation_basis' => $_POST['calculation_basis'] ?? 'Per Loan',
            'effective_from' => $effectiveFrom,
            'is_active' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Compliance', 'Added new duty stamp amount (N$' . $amount . ') effective ' . $effectiveFrom);
        Session::flash('success', 'New duty stamp amount saved.');
        $this->redirect('/compliance/settings');
    }
}
