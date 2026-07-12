<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RegulatoryReport;
use App\Models\RegulatoryReportLine;
use App\Models\RegulatoryReportType;
use App\Services\RegulatoryReportExcelExporter;
use App\Services\RegulatoryReportGenerationService;
use App\Services\ReportPeriod;

class QuarterlyReportController extends Controller
{
    private RegulatoryReport $reports;
    private RegulatoryReportLine $reportLines;
    private RegulatoryReportType $reportTypes;

    public function __construct()
    {
        $this->reports = new RegulatoryReport();
        $this->reportLines = new RegulatoryReportLine();
        $this->reportTypes = new RegulatoryReportType();
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
        if (!$type) {
            $errors['report_type_id'] = 'Select a report type.';
        }
        if ($quarter < 1 || $quarter > 4) {
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

        $period = ReportPeriod::range('quarter', $year, 0, $quarter);
        $userId = Auth::user()['id'] ?? null;

        try {
            $reportId = RegulatoryReportGenerationService::generate($type['report_code'], $period['start'], $period['end'], $userId);
        } catch (\RuntimeException $e) {
            Session::flash('error', 'Could not generate report: ' . $e->getMessage());
            $this->redirect('/compliance/quarterly-reports/create');
            return;
        }

        Audit::log('Create', 'Compliance', 'Generated ' . $type['report_name'] . ' for ' . $period['label']);
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

        $this->view('compliance/quarterly/show', [
            'title' => $report['report_name'],
            'report' => $report,
            'lines' => $this->reportLines->forReport((int) $id),
        ]);
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

        $lines = $this->reportLines->forReport($id);
        $exporter = new RegulatoryReportExcelExporter($report, $lines);
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
