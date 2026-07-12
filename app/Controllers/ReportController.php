<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\LoanReportService;
use App\Services\ReportPeriod;

class ReportController extends Controller
{
    public function index(): void
    {
        Auth::authorize('reports.financial');

        $period = ReportPeriod::fromRequest($_GET);

        $this->view('reports/index', [
            'title' => 'Financial Reports',
            'period' => $period,
            'genderBreakdown' => LoanReportService::genderBreakdown($period['start'], $period['end']),
            'sizeBreakdown' => LoanReportService::sizeBreakdown($period['start'], $period['end']),
            'salaryBreakdown' => LoanReportService::salaryBreakdown($period['start'], $period['end']),
            'paymentGenderBreakdown' => LoanReportService::paymentGenderBreakdown($period['start'], $period['end']),
            'disbursementByMonth' => LoanReportService::disbursementByMonth($period['start'], $period['end']),
            'installmentBreakdown' => LoanReportService::installmentBreakdown($period['start'], $period['end']),
            'financialMetrics' => LoanReportService::financialMetrics($period['start'], $period['end']),
            'activeLoanStatus' => LoanReportService::activeLoanStatus($period['end']),
            'badDebtsBreakdown' => LoanReportService::badDebtsBreakdown($period['start'], $period['end']),
            'badDebtRecoveries' => LoanReportService::badDebtRecoveries($period['start'], $period['end']),
        ]);
    }
}
