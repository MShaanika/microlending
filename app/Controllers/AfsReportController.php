<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\FiscalYear;
use App\Services\AfsExcelExporter;
use App\Services\AfsReportService;

class AfsReportController extends Controller
{
    private FiscalYear $fiscalYears;

    public function __construct()
    {
        $this->fiscalYears = new FiscalYear();
    }

    public function index(): void
    {
        Auth::requireLogin();

        $this->view('accounting/afs_export/index', [
            'title' => 'Annual Financial Statements Export',
            'fiscalYears' => $this->fiscalYears->allYears(),
        ]);
    }

    public function export(): void
    {
        Auth::requireLogin();

        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';

        if ($fiscalYearId = (int) ($_GET['fiscal_year_id'] ?? 0)) {
            $fy = $this->fiscalYears->find($fiscalYearId);
            if ($fy) {
                $startDate = $fy['start_date'];
                $endDate = $fy['end_date'];
            }
        }

        if (!$startDate || !$endDate || strtotime($startDate) === false || strtotime($endDate) === false) {
            \App\Core\Session::flash('error', 'Please select a valid fiscal year or date range to export.');
            $this->redirect('/accounting/afs-export');
            return;
        }

        $company = AfsReportService::companyInfo();
        $companyName = $company['company_name'] ?? 'Company';

        $exporter = new AfsExcelExporter($companyName, $startDate, $endDate);
        $spreadsheet = $exporter->build();

        $filename = 'AFS_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $companyName) . '_' . $startDate . '_to_' . $endDate . '.xlsx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $exporter->save($spreadsheet, 'php://output');
        exit;
    }
}
