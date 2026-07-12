<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\OperationalReportService;
use App\Services\ReportPeriod;

class OperationalReportController extends Controller
{
    public function index(): void
    {
        Auth::authorize('reports.operational');

        $period = ReportPeriod::fromRequest($_GET);

        $this->view('reports/operational', [
            'title' => 'Operational Reports',
            'period' => $period,
            'portfolioAtRisk' => OperationalReportService::portfolioAtRisk($period['end']),
            'collectionsEfficiency' => OperationalReportService::collectionsEfficiency($period['start'], $period['end']),
            'expenseSummary' => OperationalReportService::expenseSummary($period['start'], $period['end']),
            'debitOrderPerformance' => OperationalReportService::debitOrderPerformance($period['start'], $period['end']),
            'loanMix' => OperationalReportService::loanMix($period['start'], $period['end']),
        ]);
    }
}
