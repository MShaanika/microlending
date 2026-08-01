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

    /** Hard scope -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    public function index(): void
    {
        Auth::authorize('compliance.duty_stamp');

        $period = ReportPeriod::fromRequest($_GET);
        $status = trim((string) ($_GET['status'] ?? ''));
        $branchId = $this->indexBranchId();
        $allBranches = (new \App\Models\Branch())->all();
        $selectedBranchName = null;
        if ($branchId !== null) {
            $match = array_values(array_filter($allBranches, fn($b) => (int) $b['id'] === $branchId));
            $selectedBranchName = $match[0]['branch_name'] ?? null;
        }

        $this->view('compliance/duty_stamps', [
            'title' => 'Duty Stamps',
            'period' => $period,
            'status' => $status,
            'branches' => Auth::isSuperAdmin() ? $allBranches : [],
            'selectedBranchId' => $branchId,
            'selectedBranchName' => $selectedBranchName,
            'summary' => RegulatoryReportService::dutyStampSummary($period['start'], $period['end'], $branchId),
            'transactions' => $this->charges->paginatedDutyStampTransactions($status, $period['start'], $period['end'], 200, $branchId),
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

        $this->charges->markDutyStampSubmitted($ids, $this->scopeBranchId());

        Audit::log('Submit', 'Compliance', 'Marked ' . count($ids) . ' duty stamp transaction(s) as Submitted');
        Session::flash('success', count($ids) . ' transaction(s) marked as Submitted.');
        $this->redirect('/compliance/duty-stamps');
    }
}
