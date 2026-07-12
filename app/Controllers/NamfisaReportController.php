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

class NamfisaReportController extends Controller
{
    private StatutoryCharge $charges;

    public function __construct()
    {
        $this->charges = new StatutoryCharge();
    }

    public function index(): void
    {
        Auth::authorize('compliance.namfisa');

        $period = ReportPeriod::fromRequest($_GET);
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('compliance/namfisa', [
            'title' => 'NAMFISA Reports',
            'period' => $period,
            'status' => $status,
            'summary' => RegulatoryReportService::namfisaLevySummary($period['start'], $period['end']),
            'transactions' => $this->charges->paginatedNamfisaTransactions($status, $period['start'], $period['end']),
        ]);
    }

    public function markSubmitted(): void
    {
        Auth::authorize('compliance.namfisa');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/compliance/namfisa');
            return;
        }

        $ids = array_map('intval', $_POST['transaction_ids'] ?? []);
        if (empty($ids)) {
            Session::flash('error', 'Select at least one transaction to mark as submitted.');
            $this->redirect('/compliance/namfisa');
            return;
        }

        $this->charges->markNamfisaSubmitted($ids);

        Audit::log('Submit', 'Compliance', 'Marked ' . count($ids) . ' NAMFISA levy transaction(s) as Submitted');
        Session::flash('success', count($ids) . ' transaction(s) marked as Submitted.');
        $this->redirect('/compliance/namfisa');
    }
}
