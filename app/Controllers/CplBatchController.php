<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CplBatch;
use App\Models\CplMonthlySnapshot;
use App\Models\CplSetting;
use App\Services\ApprovalService;
use App\Services\CplExporter;
use App\Services\CplRecordBuilder;
use App\Services\CplSnapshotService;

/**
 * CPL Monthly Batch workflow (items 11-16): generate a candidate batch from
 * live data, validate it, let staff work through the error worklist and
 * revalidate, then require maker-checker approval before the extract file
 * is even generated. Nothing here transmits anything externally -- approval
 * only produces a downloadable file, matching item 15's "do not
 * automatically transmit the final monthly file without human approval".
 */
class CplBatchController extends Controller
{
    private CplBatch $batches;
    private CplMonthlySnapshot $snapshots;
    private CplSetting $settings;

    public function __construct()
    {
        $this->batches = new CplBatch();
        $this->snapshots = new CplMonthlySnapshot();
        $this->settings = new CplSetting();
    }

    public function index(): void
    {
        Auth::authorize('reports.cpl_export');

        $this->view('reports/cpl_export/batches/index', [
            'title' => 'CPL Dashboard',
            'batches' => $this->batches->recent(12),
            'lastMonthEnd' => date('Y-m-d', strtotime('last day of previous month')),
            'accountTypeMappingConfirmed' => $this->settings->isAccountTypeMappingConfirmed(),
        ]);
    }

    public function generate(): void
    {
        Auth::authorize('reports.cpl_export');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/batches');
            return;
        }

        $monthEnd = trim((string) ($_POST['month_end'] ?? ''));
        if (strtotime($monthEnd) === false) {
            Session::flash('error', 'Please select a valid month-end date.');
            $this->redirect('/reports/cpl-export/batches');
            return;
        }

        try {
            $batchId = (new CplSnapshotService())->generate($monthEnd, Auth::user()['id'] ?? null);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/reports/cpl-export/batches');
            return;
        }

        Audit::log('Create', 'CPL', 'Generated/regenerated CPL monthly batch for ' . $monthEnd);
        Session::flash('success', 'Batch prepared and validated.');
        $this->redirect('/reports/cpl-export/batches/' . $batchId);
    }

    public function show(string $id): void
    {
        Auth::authorize('reports.cpl_export');
        $batch = $this->batches->find((int) $id);
        if (!$batch) {
            Session::flash('error', 'Batch not found.');
            $this->redirect('/reports/cpl-export/batches');
            return;
        }

        $this->view('reports/cpl_export/batches/show', [
            'title' => 'CPL Batch ' . $batch['month_end'],
            'batch' => $batch,
            'counts' => $this->snapshots->countsForBatch((int) $id),
            'errorRecords' => $this->snapshots->forBatch((int) $id, 'Blocking Error'),
            'warningRecords' => $this->snapshots->forBatch((int) $id, 'Warning'),
        ]);
    }

    public function submitForApproval(string $id): void
    {
        Auth::authorize('reports.cpl_export');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $batch = $this->batches->find($id);
        if (!$batch || $batch['status'] !== 'Ready for Review') {
            Session::flash('error', 'Only a batch that is Ready for Review (no blocking errors) can be submitted for approval.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $approvalRequestId = ApprovalService::request('cpl_batch_approval', [
            'resource_id' => $id,
            'maker_user_id' => $userId,
            'title' => 'CPL monthly batch for ' . $batch['month_end'] . ' (' . $batch['total_records'] . ' records)',
            'reason' => 'Prepared and validated CPL monthly batch ready for submission.',
        ]);

        $this->batches->updateRecord($id, [
            'status' => 'Pending Approval',
            'approval_request_id' => $approvalRequestId,
        ]);

        Audit::log('Update', 'CPL', 'Submitted CPL batch #' . $id . ' for approval');
        Session::flash('success', 'Batch submitted for approval.');
        $this->redirect('/reports/cpl-export/batches/' . $id);
    }

    public function approve(string $id): void
    {
        Auth::authorize('reports.cpl_approve');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $batch = $this->batches->find($id);
        if (!$batch || $batch['status'] !== 'Pending Approval' || !$batch['approval_request_id']) {
            Session::flash('error', 'This batch is not awaiting approval.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $comments = trim((string) ($_POST['comments'] ?? ''));
        try {
            $result = ApprovalService::approve((int) $batch['approval_request_id'], $comments !== '' ? $comments : null);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        if ($result['status'] !== 'APPROVED') {
            Session::flash('success', 'Approval step recorded; another approval step is still required.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $fileContent = $this->buildFileFromSnapshots($id, $batch['month_end']);

        $this->batches->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => Auth::user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
            'file_content' => $fileContent,
        ]);

        Audit::log('Approve', 'CPL', 'Approved CPL batch #' . $id . ' for ' . $batch['month_end']);
        Session::flash('success', 'Batch approved. The extract file is ready to download.');
        $this->redirect('/reports/cpl-export/batches/' . $id);
    }

    public function reject(string $id): void
    {
        Auth::authorize('reports.cpl_approve');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $batch = $this->batches->find($id);
        $comments = trim((string) ($_POST['comments'] ?? ''));
        if (!$batch || $batch['status'] !== 'Pending Approval' || !$batch['approval_request_id']) {
            Session::flash('error', 'This batch is not awaiting approval.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        try {
            ApprovalService::reject((int) $batch['approval_request_id'], $comments);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $this->batches->updateRecord($id, [
            'status' => 'Rejected',
            'rejected_by' => Auth::user()['id'] ?? null,
            'rejected_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $comments,
        ]);

        Audit::log('Reject', 'CPL', 'Rejected CPL batch #' . $id . ': ' . $comments);
        Session::flash('success', 'Batch rejected.');
        $this->redirect('/reports/cpl-export/batches/' . $id);
    }

    public function download(string $id): void
    {
        Auth::authorize('reports.cpl_export');
        $batch = $this->batches->find((int) $id);
        if (!$batch || $batch['status'] !== 'Approved' || empty($batch['file_content'])) {
            Session::flash('error', 'Only an approved batch has a file to download.');
            $this->redirect('/reports/cpl-export/batches/' . $id);
            return;
        }

        $fileType = $this->settings->isProductionEnvironment() ? 'L702' : 'T702';
        $supplierRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $this->settings->supplierReferenceNumber() ?: 'PENDING');
        $filename = $supplierRef . '_' . $this->settings->recipient() . '_' . $fileType . '_M_' . str_replace('-', '', $batch['month_end']) . '_1_1.txt';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/plain; charset=ASCII');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        echo $batch['file_content'];
        exit;
    }

    /** Rebuilds the CPLv1.1 text purely from each snapshot's frozen field_data -- never a fresh DB query, so approval always reflects exactly what was validated and reviewed. Also permanently records any status code carried in a snapshot -- see CplExporter::recordStatusSent()'s docblock for why this only happens now, at approval, not at snapshot time. */
    private function buildFileFromSnapshots(int $batchId, string $monthEnd): string
    {
        $builder = new CplRecordBuilder();
        $exporter = new CplExporter();
        $records = $this->snapshots->forBatch($batchId);

        $lines = [];
        foreach ($records as $record) {
            $fields = json_decode($record['field_data'], true) ?: [];
            if (!empty($fields['status_code'])) {
                $exporter->recordStatusSent((int) $record['loan_id'], $fields['status_code'], $monthEnd);
            }
            $lines[] = $builder->record($fields);
        }

        $header = $builder->header($this->settings->supplierReferenceNumber(), $monthEnd, $this->settings->tradingName());
        $trailer = $builder->trailer(count($lines) + 2);

        return implode("\r\n", array_merge([$header], $lines, [$trailer]));
    }
}
