<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;
use App\Models\CplSetting;
use App\Services\CplExporter;

class CplExportController extends Controller
{
    private CplSetting $settings;

    public function __construct()
    {
        $this->settings = new CplSetting();
    }

    public function index(): void
    {
        Auth::authorize('reports.cpl_export');

        $this->view('reports/cpl_export/index', [
            'title' => 'Credit Bureau (CPL) Export',
            'supplierRef' => $this->settings->supplierReferenceNumber(),
            'tradingName' => $this->settings->tradingName(),
            'lastMonthEnd' => date('Y-m-d', strtotime('last day of previous month')),
            'accountTypeMappingConfirmed' => $this->settings->isAccountTypeMappingConfirmed(),
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

        // Blank GET overrides fall back to the persisted CPL settings --
        // see CplExporter::buildMonthly()'s own null-coalescing defaults.
        $supplierRef = trim((string) ($_GET['supplier_ref'] ?? '')) ?: null;
        $tradingName = trim((string) ($_GET['trading_name'] ?? '')) ?: null;

        $exporter = new CplExporter();
        $content = $exporter->buildMonthly($date, $supplierRef, $tradingName);

        $effectiveSupplierRef = $supplierRef ?? $this->settings->supplierReferenceNumber();
        $fileType = $this->settings->isProductionEnvironment() ? 'L702' : 'T702';
        $safeSupplierRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $effectiveSupplierRef ?: 'PENDING');
        $filename = $safeSupplierRef . '_' . $this->settings->recipient() . '_' . $fileType . '_M_' . str_replace('-', '', $date) . '_1_1.txt';

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
