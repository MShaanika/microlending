<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\FiscalYear;
use App\Services\AfsExcelExporter;
use App\Services\AfsPdfExporter;
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
        Auth::authorize('accounting.balance_sheet');

        $this->view('accounting/afs_export/index', [
            'title' => 'Annual Financial Statements Export',
            'fiscalYears' => $this->fiscalYears->allYears(),
        ]);
    }

    public function export(): void
    {
        Auth::authorize('accounting.balance_sheet');

        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        $format = ($_GET['format'] ?? '') === 'pdf' ? 'pdf' : 'xlsx';

        $fiscalYearId = (int) ($_GET['fiscal_year_id'] ?? 0) ?: null;
        if ($fiscalYearId) {
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
        $safeCompanyName = preg_replace('/[^A-Za-z0-9_-]/', '_', $companyName);

        if ($format === 'pdf') {
            // Dompdf can echo PHP deprecation notices (a version mismatch
            // in its own type hints, nothing this app controls) directly
            // to output while rendering. Explicitly buffer around the
            // build call -- rather than assuming some ambient buffer is
            // already active from php.ini's output_buffering (confirmed
            // 0 on the CLI SAPI; the web SAPI's setting isn't the same
            // thing and wasn't worth trusting) -- so those notices are
            // always captured and discarded instead of corrupting the
            // response with "headers already sent".
            ob_start();
            $pdf = AfsPdfExporter::build($companyName, $startDate, $endDate, $fiscalYearId);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $filename = 'AFS_' . $safeCompanyName . '_' . $startDate . '_to_' . $endDate . '.pdf';

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            echo $pdf;
            exit;
        }

        $exporter = new AfsExcelExporter($companyName, $startDate, $endDate, $fiscalYearId);
        $spreadsheet = $exporter->build();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = 'AFS_' . $safeCompanyName . '_' . $startDate . '_to_' . $endDate . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $exporter->save($spreadsheet, 'php://output');
        exit;
    }
}
