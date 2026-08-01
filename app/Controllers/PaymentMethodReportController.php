<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Branch;
use App\Services\RegulatoryReportService;
use App\Services\ReportPeriod;

class PaymentMethodReportController extends Controller
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
        Auth::authorize('compliance.payment_methods');

        $period = ReportPeriod::fromRequest($_GET);
        $branchId = $this->indexBranchId();

        $this->view('compliance/payment_methods', [
            'title' => 'Payment Methods Report',
            'period' => $period,
            'branches' => Auth::isSuperAdmin() ? (new Branch())->all() : [],
            'selectedBranchId' => $branchId,
            'summary' => RegulatoryReportService::paymentMethodSummary($period['start'], $period['end'], $branchId),
        ]);
    }
}
