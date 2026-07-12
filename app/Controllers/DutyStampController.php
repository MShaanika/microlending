<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\StatutoryCharge;
use App\Services\RegulatoryReportService;
use App\Services\ReportPeriod;

class DutyStampController extends Controller
{
    private StatutoryCharge $charges;

    public function __construct()
    {
        $this->charges = new StatutoryCharge();
    }

    public function index(): void
    {
        Auth::authorize('compliance.duty_stamp');

        $period = ReportPeriod::fromRequest($_GET);
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('compliance/duty_stamps', [
            'title' => 'Duty Stamps',
            'period' => $period,
            'status' => $status,
            'summary' => RegulatoryReportService::dutyStampSummary($period['start'], $period['end']),
            'transactions' => $this->charges->paginatedDutyStampTransactions($status, $period['start'], $period['end']),
        ]);
    }

    public function markSubmitted(): void
    {
        Auth::authorize('compliance.duty_stamp');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/compliance/duty-stamps');
            return;
        }

        $ids = array_map('intval', $_POST['transaction_ids'] ?? []);
        if (empty($ids)) {
            Session::flash('error', 'Select at least one transaction to mark as submitted.');
            $this->redirect('/compliance/duty-stamps');
            return;
        }

        $this->charges->markDutyStampSubmitted($ids);

        Audit::log('Submit', 'Compliance', 'Marked ' . count($ids) . ' duty stamp transaction(s) as Submitted');
        Session::flash('success', count($ids) . ' transaction(s) marked as Submitted.');
        $this->redirect('/compliance/duty-stamps');
    }
}
