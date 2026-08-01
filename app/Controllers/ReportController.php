<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Branch;
use App\Services\LoanReportService;
use App\Services\ReportPeriod;

class ReportController extends Controller
{
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
        Auth::authorize('reports.financial');

        $period = ReportPeriod::fromRequest($_GET);
        $branchId = $this->indexBranchId();

        $this->view('reports/index', [
            'title' => 'Financial Reports',
            'period' => $period,
            'branches' => Auth::isSuperAdmin() ? (new Branch())->all() : [],
            'selectedBranchId' => $branchId,
            'genderBreakdown' => LoanReportService::genderBreakdown($period['start'], $period['end'], $branchId),
            'sizeBreakdown' => LoanReportService::sizeBreakdown($period['start'], $period['end'], $branchId),
            'salaryBreakdown' => LoanReportService::salaryBreakdown($period['start'], $period['end'], $branchId),
            'paymentGenderBreakdown' => LoanReportService::paymentGenderBreakdown($period['start'], $period['end'], $branchId),
            'disbursementByMonth' => LoanReportService::disbursementByMonth($period['start'], $period['end'], $branchId),
            'installmentBreakdown' => LoanReportService::installmentBreakdown($period['start'], $period['end'], $branchId),
            'financialMetrics' => LoanReportService::financialMetrics($period['start'], $period['end'], $branchId),
            'activeLoanStatus' => LoanReportService::activeLoanStatus($period['end'], $branchId),
            'badDebtsBreakdown' => LoanReportService::badDebtsBreakdown($period['start'], $period['end'], $branchId),
            'badDebtRecoveries' => LoanReportService::badDebtRecoveries($period['start'], $period['end'], $branchId),
        ]);
    }
}
