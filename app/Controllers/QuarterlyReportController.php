<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AfsReportLine;
use App\Models\MlrReportLine;
use App\Models\RegulatoryReport;
use App\Models\RegulatoryReportLine;
use App\Models\RegulatoryReportType;
use App\Services\AfsReportExcelExporter;
use App\Services\AfsReportGenerationService;
use App\Services\MlrReportExcelExporter;
use App\Services\MlrReportGenerationService;
use App\Services\RegulatoryReportExcelExporter;
use App\Services\RegulatoryReportGenerationService;
use App\Services\ReportPeriod;

class QuarterlyReportController extends Controller
{
    private const MLR_CODE = 'MLR_SUMMARISED_QTR';
    private const AFS_CODE = 'AFS_ANNUAL';

    private RegulatoryReport $reports;
    private RegulatoryReportLine $reportLines;
    private RegulatoryReportType $reportTypes;
    private MlrReportLine $mlrLines;
    private AfsReportLine $afsLines;

    public function __construct()
    {
        $this->reports = new RegulatoryReport();
        $this->reportLines = new RegulatoryReportLine();
        $this->reportTypes = new RegulatoryReportType();
        $this->mlrLines = new MlrReportLine();
        $this->afsLines = new AfsReportLine();
    }

    public function index(): void
    {
        Auth::authorize('compliance.quarterly');

        $typeCode = trim((string) ($_GET['type'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('compliance/quarterly/index', [
            'title' => 'Quarterly Reports',
            'reports' => $this->reports->paginated($typeCode, $status),
            'reportTypes' => $this->reportTypes->allTypes(),
            'typeCode' => $typeCode,
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('compliance.quarterly');

        $this->view('compliance/quarterly/create', [
            'title' => 'Generate Quarterly Report',
            'reportTypes' => $this->reportTypes->allTypes(true),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('compliance.quarterly');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/compliance/quarterly-reports/create');
            return;
        }

        $reportTypeId = (int) ($_POST['report_type_id'] ?? 0);
        $quarter = (int) ($_POST['quarter'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        $errors = [];

        $type = $reportTypeId ? $this->reportTypes->find($reportTypeId) : null;
        $isAfs = $type && $type['report_code'] === self::AFS_CODE;

        if (!$type) {
            $errors['report_type_id'] = 'Select a report type.';
        }
        // AFS_ANNUAL isn't quarter-scoped -- the "Year" field doubles as the
        // financial year's start year (2025 = FY Apr 2025 - Mar 2026), so
        // the Quarter field is hidden/ignored for this type (see create view JS).
        if (!$isAfs && ($quarter < 1 || $quarter > 4)) {
            $errors['quarter'] = 'Select a valid quarter.';
        }
        if ($year < 2000) {
            $errors['year'] = 'Select a valid year.';
        }

        if (!empty($errors)) {
            $this->view('compliance/quarterly/create', [
                'title' => 'Generate Quarterly Report',
                'reportTypes' => $this->reportTypes->allTypes(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $userId = Auth::user()['id'] ?? null;

        try {
            if ($isAfs) {
                $reportId = AfsReportGenerationService::generate($year, $userId);
            } else {
                $period = ReportPeriod::range('quarter', $year, 0, $quarter);
                $reportId = $type['report_code'] === self::MLR_CODE
                    ? MlrReportGenerationService::generate($period['start'], $period['end'], $userId)
                    : RegulatoryReportGenerationService::generate($type['report_code'], $period['start'], $period['end'], $userId);
            }
        } catch (\RuntimeException $e) {
            Session::flash('error', 'Could not generate report: ' . $e->getMessage());
            $this->redirect('/compliance/quarterly-reports/create');
            return;
        }

        $periodLabel = $isAfs ? ('FY ' . $year . '-' . ($year + 1)) : $period['label'];
        Audit::log('Create', 'Compliance', 'Generated ' . $type['report_name'] . ' for ' . $periodLabel);
        Session::flash('success', 'Report generated.');
        $this->redirect('/compliance/quarterly-reports/' . $reportId);
    }

    public function show(string $id): void
    {
        Auth::authorize('compliance.quarterly');
        $report = $this->reports->find((int) $id);

        if (!$report) {
            Session::flash('error', 'Report not found.');
            $this->redirect('/compliance/quarterly-reports');
            return;
        }

        if ($report['report_code'] === self::MLR_CODE) {
            $this->view('compliance/quarterly/show_mlr', [
                'title' => $report['report_name'],
                'report' => $report,
                'sections' => $this->groupMlrSections($this->mlrLines->forReport((int) $id)),
            ]);
            return;
        }

        if ($report['report_code'] === self::AFS_CODE) {
            $this->view('compliance/quarterly/show_afs', [
                'title' => $report['report_name'],
                'report' => $report,
                'sections' => $this->groupAfsSections($this->afsLines->forReport((int) $id)),
            ]);
            return;
        }

        $this->view('compliance/quarterly/show', [
            'title' => $report['report_name'],
            'report' => $report,
            'lines' => $this->reportLines->forReport((int) $id),
        ]);
    }

    private function groupAfsSections(array $lines): array
    {
        $sections = ['QUARTERLY_SUMMARY' => [], 'BANK_ACCOUNTS' => [], 'FIXED_ASSETS' => []];
        foreach ($lines as $line) {
            $sections[$line['section']][] = $line;
        }
        return $sections;
    }

    private function groupMlrSections(array $lines): array
    {
        $sections = [
            'DISBURSED' => [], 'GENDER' => [], 'SIZE' => [], 'BOOK_BALANCE' => [],
            'WRITTEN_OFF' => [], 'EXPENSES' => [], 'INTEREST_INCOME' => [], 'LEVY' => [],
        ];
        foreach ($lines as $line) {
            $sections[$line['section']][] = $line;
        }
        return $sections;
    }

    public function submit(string $id): void
    {
        $this->transition($id, 'Generated', 'Submitted');
    }

    public function approve(string $id): void
    {
        $this->transition($id, 'Submitted', 'Approved');
    }

    public function reject(string $id): void
    {
        $this->transition($id, 'Submitted', 'Rejected');
    }

    private function transition(string $id, string $fromStatus, string $toStatus): void
    {
        Auth::authorize('compliance.quarterly');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/compliance/quarterly-reports/' . $id);
            return;
        }

        $report = $this->reports->find($id);
        if (!$report || $report['status'] !== $fromStatus) {
            Session::flash('error', 'This report cannot be ' . strtolower($toStatus) . ' from its current status.');
            $this->redirect('/compliance/quarterly-reports/' . $id);
            return;
        }

        $this->reports->updateStatus($id, $toStatus, Auth::user()['id'] ?? null);

        Audit::log($toStatus, 'Compliance', 'Marked report #' . $id . ' (' . $report['report_no'] . ') as ' . $toStatus);
        Session::flash('success', 'Report marked as ' . $toStatus . '.');
        $this->redirect('/compliance/quarterly-reports/' . $id);
    }

    public function download(string $id): void
    {
        Auth::authorize('compliance.quarterly');
        $id = (int) $id;
        $report = $this->reports->find($id);

        if (!$report) {
            Session::flash('error', 'Report not found.');
            $this->redirect('/compliance/quarterly-reports');
            return;
        }

        if ($report['report_code'] === self::MLR_CODE) {
            $exporter = new MlrReportExcelExporter($report, $this->groupMlrSections($this->mlrLines->forReport($id)));
        } elseif ($report['report_code'] === self::AFS_CODE) {
            $exporter = new AfsReportExcelExporter($report, $this->groupAfsSections($this->afsLines->forReport($id)));
        } else {
            $exporter = new RegulatoryReportExcelExporter($report, $this->reportLines->forReport($id));
        }
        $spreadsheet = $exporter->build();

        $targetDir = STORAGE_PATH . '/regulatory_exports';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $report['report_no']) . '_' . date('YmdHis') . '.xlsx';
        $fullPath = $targetDir . '/' . $filename;
        $exporter->save($spreadsheet, $fullPath);

        $userId = Auth::user()['id'] ?? null;
        $relativePath = 'regulatory_exports/' . $filename;
        $this->reports->logExport($id, generate_reference('EXP'), $relativePath, $userId);
        $this->reports->markExported($id, $relativePath);

        Audit::log('Export', 'Compliance', 'Exported report #' . $id . ' (' . $report['report_no'] . ') to Excel');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        readfile($fullPath);
        exit;
    }
}
