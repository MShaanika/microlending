<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CplBatch;
use App\Models\CplSetting;
use App\Services\CplSftpUncertainException;
use App\Services\CplUatBatchService;
use App\Services\CplUatFileService;
use App\Services\CplUatUploadService;
use App\Services\CreditinfoSignOffService;

/**
 * Creditinfo sign-off testing for CPL Daily and CPL Monthly -- generates a
 * file from synthetic data only (App\Services\CplUatTestDataService, built
 * exclusively from Creditinfo's three approved UAT National IDs), validates
 * it against the CPLv1.1 spec, can upload that test file (T702, synthetic
 * data only -- see CplUatUploadService) to Creditinfo's SFTP gateway on an
 * explicit click -- or send a standard test file made earlier and chosen from
 * the computer, byte for byte as it is -- and lets staff record the actual
 * outcome once Creditinfo has processed it. Never marks a file ACCEPTED
 * automatically -- see CplSetting/CreditinfoSignOffService's own docblocks.
 * Uploading a file is not an outcome: the batch only becomes "Awaiting Load
 * Report".
 */
class CplUatTestController extends Controller
{
    /** A real sign-off test file is about 5 KB; anything much larger is not one. */
    private const MAX_TEST_FILE_BYTES = 65536;

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
            'fileUpload' => (new CplUatUploadService())->connectionStatus(),
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

        $batch = (new CplUatBatchService())->generate('Daily', date('Y-m-d'), (int) (Auth::user()['id'] ?? 0));

        Audit::log('Create', 'CPL', 'Generated CPL Daily UAT sign-off test file (' . $batch['records'] . ' records)');
        Session::flash('success', 'CPL Daily test file generated and validated.');
        $this->redirect('/reports/cpl-export/uat-signoff/' . $batch['id']);
    }

    public function generateMonthly(): void
    {
        Auth::authorize('reports.cpl_export');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/uat-signoff');
            return;
        }

        // The last COMPLETED month end, like the real monthly batch screen offers -- a monthly
        // file is "as at" a month end that has passed, so "this month's" end is a future date
        // until the month is over, and a bureau can reject a file dated in the future.
        $monthEnd = date('Y-m-d', strtotime('last day of previous month'));
        $batch = (new CplUatBatchService())->generate('Monthly', $monthEnd, (int) (Auth::user()['id'] ?? 0));

        Audit::log('Create', 'CPL', 'Generated CPL Monthly UAT sign-off test file');
        Session::flash('success', 'CPL Monthly test file generated and validated.');
        $this->redirect('/reports/cpl-export/uat-signoff/' . $batch['id']);
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
            'testRecords' => json_decode((string) $batch['test_records'], true) ?: [],
            'upload' => (new CplUatUploadService())->describe($batch),
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

        // Sign-off test files are always the "Test" file type (T702),
        // regardless of the configured submission environment -- this file
        // must never be mistaken for a live production submission. The same
        // builder feeds the Upload button, so an uploaded file is what this
        // download gives (a monthly header carries the day it was built).
        try {
            $file = (new CplUatFileService())->build($batch, $this->settings->supplierReferenceNumber(), $this->settings->tradingName());
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/reports/cpl-export/uat-signoff/' . (int) $id);
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=ASCII');
        header('Content-Disposition: attachment;filename="' . $file['filename'] . '"');
        echo $file['content'];
        exit;
    }

    /**
     * Explicit click only. Sends this batch's synthetic T702 test file to
     * Creditinfo over the SFTP connection saved in Public Defaults Settings,
     * then marks the batch "Awaiting Load Report". The guards (test IDs only,
     * T702 only, no blocking errors) and the order of events live in
     * CplUatUploadService; a refused or failed upload leaves the batch as it was.
     */
    public function uploadToCreditinfo(string $id): void
    {
        Auth::authorize('reports.cpl_export');
        $id = (int) $id;
        $back = '/reports/cpl-export/uat-signoff/' . $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect($back);
            return;
        }

        try {
            $result = (new CplUatUploadService())->upload($id, (int) (Auth::user()['id'] ?? 0));
        } catch (\RuntimeException $e) {
            // A connection that broke after data was written is not "did not go through": the file may be there.
            $unknown = $e instanceof CplSftpUncertainException;
            Audit::log('Update', 'CPL', 'Upload of CPL sign-off test batch #' . $id . ' to Creditinfo SFTP '
                . ($unknown ? 'ended with an UNKNOWN result, the file may have reached Creditinfo: ' : 'did not go through: ') . $e->getMessage());
            Session::flash('error', ($unknown ? 'The upload to Creditinfo may or may not have worked. ' : 'The upload to Creditinfo did not go through. ') . $e->getMessage());
            $this->redirect($back);
            return;
        } catch (\Throwable $e) {
            error_log('CPL sign-off test upload, batch #' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
            Audit::log('Update', 'CPL', 'Upload of CPL sign-off test batch #' . $id . ' to Creditinfo SFTP failed with an unexpected error (' . get_class($e) . ')');
            Session::flash('error', 'Something unexpected went wrong, so we cannot tell whether Creditinfo received the file. Do not upload it again yet: ask Creditinfo whether it arrived, and tell your administrator.');
            $this->redirect($back);
            return;
        }

        Audit::log('Update', 'CPL', 'Uploaded CPL ' . $result['kind'] . ' sign-off TEST file ' . $result['filename'] . ' to Creditinfo SFTP (batch #' . $id . ')', [
            'batch_id' => $id,
            'file' => $result['filename'],
            'host' => $result['host'],
            'remote_path' => $result['remote_path'],
            'bytes' => $result['bytes'],
            'records' => $result['record_count'],
        ]);

        if ($result['recorded']) {
            Session::flash('success', $result['filename'] . ' (' . $result['record_count'] . ' records) was uploaded to Creditinfo. Now email your Creditinfo contacts so they load it, and record their load report below.');
        } else {
            // flash_messages() only shows 'success' and 'error'; this one must not pass unnoticed.
            Session::flash('error', $result['filename'] . ' WAS uploaded to Creditinfo at ' . date('d M Y H:i', strtotime($result['uploaded_at']))
                . ', but that could not be saved on this page. Note the time yourself, email your Creditinfo contacts, and do not upload it again.');
        }
        $this->redirect($back);
    }

    /**
     * Explicit click only. Sends a test file CHOSEN FROM THE COMPUTER -- one made earlier and kept, for example in the
     * Downloads folder -- to Creditinfo exactly as it is, but only if it is the standard sign-off test file for the date
     * in its name (CplUatFileService::inspectStandardFile). Anything else is refused before any connection is made.
     * The browser's temporary copy is read once and never stored; only its base name is used, and only if it passes the
     * strict test-file-name rule.
     */
    public function uploadFileToCreditinfo(): void
    {
        Auth::authorize('reports.cpl_export');
        $back = '/reports/cpl-export/uat-signoff';
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect($back);
            return;
        }

        $upload = $_FILES['test_file'] ?? null;
        $uploadError = is_array($upload) && !is_array($upload['name'] ?? null) ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            Session::flash('error', in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is far too large to be a sign-off test file. Nothing was sent.'
                : 'Choose the test file to upload first. Nothing was sent.');
            $this->redirect($back);
            return;
        }
        $tmpName = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if (!is_uploaded_file($tmpName) || $size < 1 || $size > self::MAX_TEST_FILE_BYTES) {
            Session::flash('error', 'That is not a sign-off test file (it is empty or far too large). Nothing was sent.');
            $this->redirect($back);
            return;
        }

        $filename = basename(str_replace('\\', '/', (string) ($upload['name'] ?? '')));
        $content = (string) file_get_contents($tmpName);
        $fingerprint = ['sha256' => hash('sha256', $content), 'bytes' => strlen($content)];

        try {
            $result = (new CplUatUploadService())->uploadFile($filename, $content, (int) (Auth::user()['id'] ?? 0));
        } catch (\RuntimeException $e) {
            $unknown = $e instanceof CplSftpUncertainException;
            Audit::log('Update', 'CPL', 'Upload of a chosen CPL sign-off test file to Creditinfo SFTP '
                . ($unknown ? 'ended with an UNKNOWN result, the file may have reached Creditinfo: ' : 'did not go through: ') . $e->getMessage(), $fingerprint);
            Session::flash('error', ($unknown ? 'The upload to Creditinfo may or may not have worked. ' : 'The upload to Creditinfo did not go through. ') . $e->getMessage());
            $this->redirect($back);
            return;
        } catch (\Throwable $e) {
            error_log('CPL sign-off test file upload from the computer: ' . get_class($e) . ': ' . $e->getMessage());
            Audit::log('Update', 'CPL', 'Upload of a chosen CPL sign-off test file to Creditinfo SFTP failed with an unexpected error (' . get_class($e) . ')', $fingerprint);
            Session::flash('error', 'Something unexpected went wrong, so we cannot tell whether Creditinfo received the file. Do not upload it again yet: ask Creditinfo whether it arrived, and tell your administrator.');
            $this->redirect($back);
            return;
        }

        Audit::log('Update', 'CPL', 'Uploaded CPL ' . $result['kind'] . ' sign-off TEST file ' . $result['filename'] . ' to Creditinfo SFTP (file chosen from the computer'
            . ($result['batch_id'] !== null ? ', recorded on batch #' . $result['batch_id'] : '') . ')', $fingerprint + [
            'file' => $result['filename'],
            'host' => $result['host'],
            'remote_path' => $result['remote_path'],
            'records' => $result['record_count'],
            'batch_id' => $result['batch_id'],
        ]);

        if ($result['recorded'] && $result['batch_id'] !== null) {
            Session::flash('success', $result['filename'] . ' (' . $result['record_count'] . ' records) was uploaded to Creditinfo. Now email your Creditinfo contacts so they load it, and record their load report below.');
            $this->redirect('/reports/cpl-export/uat-signoff/' . $result['batch_id']);
            return;
        }

        // flash_messages() only shows 'success' and 'error'; this one must not pass unnoticed.
        Session::flash('error', $result['filename'] . ' WAS uploaded to Creditinfo at ' . date('d M Y H:i', strtotime($result['uploaded_at']))
            . ', but that could not be saved on the sign-off page. Note the time yourself, email your Creditinfo contacts, and do not upload it again.');
        $this->redirect($back);
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
