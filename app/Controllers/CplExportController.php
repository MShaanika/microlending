<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;
use App\Services\AfsReportService;
use App\Services\CplExporter;

class CplExportController extends Controller
{
    public function index(): void
    {
        Auth::authorize('reports.cpl_export');

        $company = AfsReportService::companyInfo();

        $this->view('reports/cpl_export/index', [
            'title' => 'Credit Bureau (CPL) Export',
            'tradingName' => $company['company_name'] ?? 'Solid Desert Cash Loan',
            'lastMonthEnd' => date('Y-m-d', strtotime('last day of previous month')),
        ]);
    }

    public function download(string $date): void
    {
        Auth::authorize('reports.cpl_export');

        if (strtotime($date) === false) {
            Session::flash('error', 'Please select a valid month-end date to export.');
            $this->redirect('/reports/cpl-export');
            return;
        }

        $supplierRef = trim((string) ($_GET['supplier_ref'] ?? ''));
        $tradingName = trim((string) ($_GET['trading_name'] ?? '')) ?: 'Solid Desert Cash Loan';

        $exporter = new CplExporter();
        $content = $exporter->buildMonthly($date, $supplierRef, $tradingName);

        $safeSupplierRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $supplierRef ?: 'PENDING');
        $filename = $safeSupplierRef . '_ALL_T702_M_' . str_replace('-', '', $date) . '_1_1.txt';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/plain; charset=ASCII');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        echo $content;
        exit;
    }
}
