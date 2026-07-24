<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\RegulatoryReportService;
use App\Services\ReportPeriod;

class PaymentMethodReportController extends Controller
{
    public function index(): void
    {
        Auth::authorize('compliance.payment_methods');

        $period = ReportPeriod::fromRequest($_GET);

        $this->view('compliance/payment_methods', [
            'title' => 'Payment Methods Report',
            'period' => $period,
            'summary' => RegulatoryReportService::paymentMethodSummary($period['start'], $period['end']),
        ]);
    }
}
