<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Branch;
use App\Services\RegulatoryReportService;
use App\Services\ReportPeriod;

class RegulatoryReportController extends Controller
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
        Auth::authorize('reports.regulatory');

        $period = ReportPeriod::fromRequest($_GET);
        $branchId = $this->indexBranchId();
        $allBranches = (new Branch())->all();
        $selectedBranchName = null;
        if ($branchId !== null) {
            $match = array_values(array_filter($allBranches, fn($b) => (int) $b['id'] === $branchId));
            $selectedBranchName = $match[0]['branch_name'] ?? null;
        }

        $this->view('reports/regulatory', [
            'title' => 'Regulatory Reports',
            'period' => $period,
            'branches' => Auth::isSuperAdmin() ? $allBranches : [],
            'selectedBranchId' => $branchId,
            'selectedBranchName' => $selectedBranchName,
            'namfisaLevySummary' => RegulatoryReportService::namfisaLevySummary($period['start'], $period['end'], $branchId),
            'dutyStampSummary' => RegulatoryReportService::dutyStampSummary($period['start'], $period['end'], $branchId),
            'badDebtWriteOffSummary' => RegulatoryReportService::badDebtWriteOffSummary($period['start'], $period['end'], $branchId),
            'recoverySummary' => RegulatoryReportService::recoverySummary($period['start'], $period['end'], $branchId),
        ]);
    }
}
