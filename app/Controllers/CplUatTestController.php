<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CplBatch;
use App\Models\CplSetting;
use App\Services\CplRecordBuilder;
use App\Services\CplUatTestDataService;
use App\Services\CplValidationService;
use App\Services\CreditinfoSignOffService;

/**
 * Creditinfo sign-off testing for CPL Daily and CPL Monthly -- generates a
 * file from synthetic data only (App\Services\CplUatTestDataService, built
 * exclusively from Creditinfo's three approved UAT National IDs), validates
 * it against the CPLv1.1 spec, and lets staff record the actual outcome
 * once Creditinfo has processed it. Never marks a file ACCEPTED
 * automatically -- see CplSetting/CreditinfoSignOffService's own docblocks.
 * No external submission happens from this controller; "submitting" to
 * Creditinfo remains a manual, out-of-band step staff record the result of.
 */
class CplUatTestController extends Controller
{
    private CplBatch $batches;
    private CplSetting $settings;

    public function __construct()
    {
        $this->batches = new CplBatch();
        $this->settings = new CplSetting();
    }

    public function index(): void
    {
        Auth::authorize('reports.cpl_export');
        $signOff = new CreditinfoSignOffService();

        $this->view('reports/cpl_export/uat_signoff/index', [
            'title' => 'Creditinfo Integration Sign-off',
            'authStatus' => $signOff->authenticationStatus(),
            'searchStatus' => $signOff->smartSearchStatus(),
            'reportStatus' => $signOff->reportPlusStatus(),
            'pdfStatus' => $signOff->pdfRetrievalStatus(),
            'cbsPass' => $signOff->cbsApiOverallPass(),
            'dailyStatus' => $signOff->cplStatus('Daily'),
            'monthlyStatus' => $signOff->cplStatus('Monthly'),
            'overall' => $signOff->overallSignOffStatus(),
            'latestDailyBatch' => $this->batches->latestOfType('Daily', true),
            'latestMonthlyBatch' => $this->batches->latestOfType('Monthly', true),
        ]);
    }

    public function generateDaily(): void
    {
        Auth::authorize('reports.cpl_export');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/uat-signoff');
            return;
        }

        $transactionDate = date('Y-m-d');
        $records = (new CplUatTestDataService())->dailyTestRecords($transactionDate);
        $validator = new CplValidationService();

        $blockingCount = 0;
        $warningCount = 0;
        $testRecords = [];
        foreach ($records as $fields) {
            $issues = $validator->validate($fields);
            $status = $validator->overallStatus($issues);
            if ($status === 'Blocking Error') {
                $blockingCount++;
            } elseif ($status === 'Warning') {
                $warningCount++;
            }
            $testRecords[] = ['fields' => $fields, 'issues' => $issues, 'status' => $status];
        }

        $existing = $this->batches->findByTypeAndMonth('Daily', $transactionDate, true);
        $data = [
            'batch_type' => 'Daily',
            'is_uat_test' => 1,
            'month_end' => $transactionDate,
            'status' => $blockingCount > 0 ? 'Draft' : 'Ready for Review',
            'total_records' => count($records),
            'blocking_error_count' => $blockingCount,
            'warning_count' => $warningCount,
            'generated_by' => Auth::user()['id'] ?? null,
            'generated_at' => date('Y-m-d H:i:s'),
            'test_records' => json_encode($testRecords),
        ];

        if ($existing) {
            $this->batches->updateRecord((int) $existing['id'], $data);
            $batchId = (int) $existing['id'];
        } else {
            $batchId = $this->batches->create($data);
        }

        Audit::log('Create', 'CPL', 'Generated CPL Daily UAT sign-off test file (' . count($records) . ' records)');
        Session::flash('success', 'CPL Daily test file generated and validated.');
        $this->redirect('/reports/cpl-export/uat-signoff/' . $batchId);
    }

    public function generateMonthly(): void
    {
        Auth::authorize('reports.cpl_export');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/uat-signoff');
            return;
        }

        $monthEnd = date('Y-m-d', strtotime('last day of this month'));
        $fields = (new CplUatTestDataService())->monthlyTestRecord($monthEnd);
        $validator = new CplValidationService();
        $issues = $validator->validate($fields);
        $status = $validator->overallStatus($issues);
        $blockingCount = $status === 'Blocking Error' ? 1 : 0;
        $warningCount = $status === 'Warning' ? 1 : 0;

        $testRecords = [['fields' => $fields, 'issues' => $issues, 'status' => $status]];

        $existing = $this->batches->findByTypeAndMonth('Monthly', $monthEnd, true);
        $data = [
            'batch_type' => 'Monthly',
            'is_uat_test' => 1,
            'month_end' => $monthEnd,
            'status' => $blockingCount > 0 ? 'Draft' : 'Ready for Review',
            'total_records' => 1,
            'blocking_error_count' => $blockingCount,
            'warning_count' => $warningCount,
            'generated_by' => Auth::user()['id'] ?? null,
            'generated_at' => date('Y-m-d H:i:s'),
            'test_records' => json_encode($testRecords),
        ];

        if ($existing) {
            $this->batches->updateRecord((int) $existing['id'], $data);
            $batchId = (int) $existing['id'];
        } else {
            $batchId = $this->batches->create($data);
        }

        Audit::log('Create', 'CPL', 'Generated CPL Monthly UAT sign-off test file');
        Session::flash('success', 'CPL Monthly test file generated and validated.');
        $this->redirect('/reports/cpl-export/uat-signoff/' . $batchId);
    }

    public function show(string $id): void
    {
        Auth::authorize('reports.cpl_export');
        $batch = $this->batches->find((int) $id);
        if (!$batch || !$batch['is_uat_test']) {
            Session::flash('error', 'Test batch not found.');
            $this->redirect('/reports/cpl-export/uat-signoff');
            return;
        }

        $this->view('reports/cpl_export/uat_signoff/show', [
            'title' => 'CPL ' . $batch['batch_type'] . ' Sign-off Test',
            'batch' => $batch,
            'testRecords' => json_decode($batch['test_records'], true) ?: [],
        ]);
    }

    public function download(string $id): void
    {
        Auth::authorize('reports.cpl_export');
        $batch = $this->batches->find((int) $id);
        if (!$batch || !$batch['is_uat_test']) {
            Session::flash('error', 'Test batch not found.');
            $this->redirect('/reports/cpl-export/uat-signoff');
            return;
        }

        $testRecords = json_decode($batch['test_records'], true) ?: [];
        $builder = new CplRecordBuilder();
        // Sign-off test files are always the "Test" file type (T702),
        // regardless of the configured submission environment -- this file
        // must never be mistaken for a live production submission.
        $fileType = 'T702';
        $supplierRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $this->settings->supplierReferenceNumber() ?: 'PENDING');

        if ($batch['batch_type'] === 'Daily') {
            $lines = [];
            foreach ($testRecords as $record) {
                $lines[] = $builder->dailyRecord($record['fields'], $this->settings->supplierReferenceNumber(), $batch['month_end']);
            }
            $content = implode("\r\n", $lines);
            $filename = $supplierRef . '_ALL_' . $fileType . '_D_' . str_replace('-', '', $batch['month_end']) . '_1_1.txt';
        } else {
            $lines = [];
            foreach ($testRecords as $record) {
                $lines[] = $builder->record($record['fields']);
            }
            $header = $builder->header($this->settings->supplierReferenceNumber(), $batch['month_end'], $this->settings->tradingName());
            $trailer = $builder->trailer(count($lines) + 2);
            $content = implode("\r\n", array_merge([$header], $lines, [$trailer]));
            $filename = $supplierRef . '_ALL_' . $fileType . '_M_' . str_replace('-', '', $batch['month_end']) . '_1_1.txt';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=ASCII');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        echo $content;
        exit;
    }

    public function recordOutcome(string $id): void
    {
        Auth::authorize('reports.cpl_export');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/uat-signoff/' . $id);
            return;
        }

        $batch = $this->batches->find($id);
        if (!$batch || !$batch['is_uat_test']) {
            Session::flash('error', 'Test batch not found.');
            $this->redirect('/reports/cpl-export/uat-signoff');
            return;
        }

        $outcome = $_POST['submission_outcome'] ?? '';
        if (!in_array($outcome, ['Awaiting Load Report', 'Accepted', 'Rejected', 'Partially Rejected'], true)) {
            Session::flash('error', 'Select a valid outcome.');
            $this->redirect('/reports/cpl-export/uat-signoff/' . $id);
            return;
        }

        $this->batches->updateRecord($id, [
            'submission_outcome' => $outcome,
            'submission_notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'submission_recorded_by' => Auth::user()['id'] ?? null,
            'submission_recorded_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Update', 'CPL', 'Recorded ' . $outcome . ' for CPL ' . $batch['batch_type'] . ' sign-off test batch #' . $id);
        Session::flash('success', 'Outcome recorded.');
        $this->redirect('/reports/cpl-export/uat-signoff/' . $id);
    }
}
