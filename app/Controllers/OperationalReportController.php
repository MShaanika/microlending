<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Branch;
use App\Services\OperationalReportService;
use App\Services\ReportPeriod;

class OperationalReportController extends Controller
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
        Auth::authorize('reports.operational');

        $period = ReportPeriod::fromRequest($_GET);
        $branchId = $this->indexBranchId();

        $this->view('reports/operational', [
            'title' => 'Operational Reports',
            'period' => $period,
            'branches' => Auth::isSuperAdmin() ? (new Branch())->all() : [],
            'selectedBranchId' => $branchId,
            'portfolioAtRisk' => OperationalReportService::portfolioAtRisk($period['end'], $branchId),
            'collectionsEfficiency' => OperationalReportService::collectionsEfficiency($period['start'], $period['end'], $branchId),
            'expenseSummary' => OperationalReportService::expenseSummary($period['start'], $period['end'], $branchId),
            'debitOrderPerformance' => OperationalReportService::debitOrderPerformance($period['start'], $period['end'], $branchId),
            'loanMix' => OperationalReportService::loanMix($period['start'], $period['end'], $branchId),
        ]);
    }
}
