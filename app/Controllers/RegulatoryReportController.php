<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\RegulatoryReportService;
use App\Services\ReportPeriod;

class RegulatoryReportController extends Controller
{
    public function index(): void
    {
        Auth::authorize('reports.regulatory');

        $period = ReportPeriod::fromRequest($_GET);

        $this->view('reports/regulatory', [
            'title' => 'Regulatory Reports',
            'period' => $period,
            'namfisaLevySummary' => RegulatoryReportService::namfisaLevySummary($period['start'], $period['end']),
            'dutyStampSummary' => RegulatoryReportService::dutyStampSummary($period['start'], $period['end']),
            'badDebtWriteOffSummary' => RegulatoryReportService::badDebtWriteOffSummary($period['start'], $period['end']),
            'recoverySummary' => RegulatoryReportService::recoverySummary($period['start'], $period['end']),
        ]);
    }
}
