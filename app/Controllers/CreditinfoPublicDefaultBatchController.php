<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\IdempotencyBusyException;
use App\Core\IdempotencyReplayException;
use App\Core\Security;
use App\Core\Session;
use App\Models\ApprovalRequest;
use App\Models\CreditinfoPublicDefaultBatch;
use App\Models\CreditinfoPublicDefaultBatchItem;
use App\Models\CreditinfoPublicDefaultSetting;
use App\Services\ApprovalService;
use App\Services\CreditinfoPublicDefaultBatchService;

/**
 * Public Defaults submission FILE workflow -- generating the batched
 * pipe-delimited .txt Creditinfo requires, a maker-checker review of it
 * (via the existing generic Approval Engine), and downloading it for a
 * human to actually upload via Creditinfo's own SFTP client. There is no
 * SFTP call anywhere in this controller or CreditinfoPublicDefaultBatchService
 * -- see database/creditinfo_public_defaults_submission_file.sql's header
 * comment for why that boundary is deliberate.
 */
class CreditinfoPublicDefaultBatchController extends Controller
{
    private const DIRECTIONS = ['listing', 'removal'];

    private CreditinfoPublicDefaultBatch $batches;
    private CreditinfoPublicDefaultBatchItem $items;
    private CreditinfoPublicDefaultSetting $settings;
    private CreditinfoPublicDefaultBatchService $service;

    public function __construct()
    {
        $this->batches = new CreditinfoPublicDefaultBatch();
        $this->items = new CreditinfoPublicDefaultBatchItem();
        $this->settings = new CreditinfoPublicDefaultSetting();
        $this->service = new CreditinfoPublicDefaultBatchService();
    }

    private function assertDirection(string $direction): void
    {
        if (!in_array($direction, self::DIRECTIONS, true)) {
            http_response_code(404);
            $this->view('errors/404', ['title' => 'Page Not Found']);
            exit;
        }
    }

    public function index(string $direction): void
    {
        $this->assertDirection($direction);
        Auth::authorize('creditinfo.public_defaults.view');

        $this->view('creditinfo/public_defaults/batches/index', [
            'title' => ucfirst($direction) . ' Submission Batches',
            'direction' => $direction,
            'eligible' => $this->service->eligible($direction),
            'batches' => $this->batches->forDirection($direction),
            'environment' => $this->settings->submissionEnvironment(),
        ]);
    }

    public function generate(string $direction): void
    {
        $this->assertDirection($direction);
        Auth::authorize('creditinfo.public_defaults.generate_batch');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction);
            return;
        }

        $ids = array_map('intval', (array) ($_POST['public_default_ids'] ?? []));
        $requestedEnvironment = ($_POST['environment'] ?? 'test') === 'live' ? 'live' : 'test';
        $userId = (int) (Auth::user()['id'] ?? 0);
        $key = $this->idempotencyKey();

        try {
            $result = $this->service->generate($direction, $ids, $requestedEnvironment, $userId, $key);
            Audit::log('Create', 'Creditinfo', 'Generated Public Defaults ' . $direction . ' submission batch (' . count($ids) . ' record(s))', [], (string) $result);
        } catch (IdempotencyReplayException $e) {
            $this->replayIdempotent($e);
            return;
        } catch (IdempotencyBusyException $e) {
            $this->busyIdempotent($e, '/creditinfo/public-defaults/batches/' . $direction);
            return;
        } catch (\RuntimeException|\LogicException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction);
            return;
        }

        Session::flash('success', 'Submission file generated. It needs approval before it can be downloaded.');
        $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $result);
    }

    public function show(string $direction, string $id): void
    {
        $this->assertDirection($direction);
        Auth::authorize('creditinfo.public_defaults.view');
        $batch = $this->batches->find((int) $id);
        if (!$batch || $batch['direction'] !== $direction) {
            Session::flash('error', 'Batch not found.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction);
            return;
        }

        $this->view('creditinfo/public_defaults/batches/show', [
            'title' => 'Submission Batch ' . $batch['batch_reference'],
            'direction' => $direction,
            'batch' => $batch,
            'items' => $this->items->forBatch((int) $batch['id']),
        ]);
    }

    public function approve(string $direction, string $id): void
    {
        $this->decide($direction, $id, true);
    }

    public function reject(string $direction, string $id): void
    {
        $this->decide($direction, $id, false);
    }

    private function decide(string $direction, string $idStr, bool $approve): void
    {
        $this->assertDirection($direction);
        Auth::authorize('creditinfo.public_defaults.approve_batch');
        $id = (int) $idStr;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $id);
            return;
        }

        $batch = $this->batches->find($id);
        if (!$batch || $batch['direction'] !== $direction || $batch['status'] !== 'Pending Review') {
            Session::flash('error', 'Only a batch Pending Review can be decided.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $id);
            return;
        }

        $comments = trim((string) ($_POST['comments'] ?? ''));
        $approvalRequest = (new ApprovalRequest())->findPendingByResource('Creditinfo', 'public_default_batch', $id);
        if (!$approvalRequest) {
            Session::flash('error', 'No open approval request found for this batch.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $id);
            return;
        }

        $userId = (int) (Auth::user()['id'] ?? 0);

        try {
            if ($approve) {
                ApprovalService::approve((int) $approvalRequest['id'], $comments !== '' ? $comments : null);
            } else {
                ApprovalService::reject((int) $approvalRequest['id'], $comments);
            }
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $id);
            return;
        }

        if ($approve) {
            $this->batches->updateFields($id, [
                'status' => 'Approved',
                'reviewed_by' => $userId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_comments' => $comments ?: null,
            ]);
            Audit::log('Approve', 'Creditinfo', 'Approved Public Defaults submission batch ' . $batch['batch_reference'], [], $batch['batch_reference']);
            Session::flash('success', 'Batch approved. It can now be downloaded for upload to Creditinfo.');
        } else {
            $this->batches->updateFields($id, [
                'status' => 'Rejected',
                'reviewed_by' => $userId,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_comments' => $comments,
            ]);
            // Items in a Rejected batch are released back to the eligible
            // pool automatically -- CreditinfoPublicDefaultBatch::
            // hasOpenBatchForPublicDefault() excludes Rejected batches --
            // the underlying creditinfo_public_defaults rows never changed
            // status, so nothing else needs to unwind here.
            Audit::log('Reject', 'Creditinfo', 'Rejected Public Defaults submission batch ' . $batch['batch_reference'] . ': ' . $comments, [], $batch['batch_reference']);
            Session::flash('success', 'Batch rejected. Its records are available to include in a new batch.');
        }

        $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $id);
    }

    /** Streams the stored file; first download flips Approved -> Downloaded, a repeat download just re-streams without re-stamping. */
    public function download(string $direction, string $id): void
    {
        $this->assertDirection($direction);
        Auth::authorize('creditinfo.public_defaults.submit');
        $batch = $this->batches->find((int) $id);
        if (!$batch || $batch['direction'] !== $direction || !in_array($batch['status'], ['Approved', 'Downloaded', 'Marked Submitted'], true)) {
            Session::flash('error', 'This batch is not approved for download yet.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $batch['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $id);
            return;
        }

        if ($batch['status'] === 'Approved') {
            $userId = (int) (Auth::user()['id'] ?? 0);
            $this->batches->updateFields((int) $batch['id'], [
                'status' => 'Downloaded',
                'downloaded_by' => $userId,
                'downloaded_at' => date('Y-m-d H:i:s'),
            ]);
            Audit::log('Read', 'Creditinfo', 'Downloaded Public Defaults submission batch ' . $batch['batch_reference'], [], $batch['batch_reference']);
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $batch['filename'] . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    public function markSubmitted(string $direction, string $id): void
    {
        $this->assertDirection($direction);
        Auth::authorize('creditinfo.public_defaults.submit');
        $idInt = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $idInt);
            return;
        }

        $creditinfoReference = trim((string) ($_POST['creditinfo_reference'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $userId = (int) (Auth::user()['id'] ?? 0);
        $key = $this->idempotencyKey();

        try {
            $this->service->markSubmitted($idInt, $creditinfoReference, $notes, $userId, $key);
        } catch (IdempotencyReplayException $e) {
            $this->replayIdempotent($e);
            return;
        } catch (IdempotencyBusyException $e) {
            $this->busyIdempotent($e, '/creditinfo/public-defaults/batches/' . $direction . '/' . $idInt);
            return;
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $idInt);
            return;
        }

        Audit::log('Update', 'Creditinfo', 'Marked Public Defaults submission batch #' . $idInt . ' as submitted');
        Session::flash('success', 'Batch marked submitted. Confirm each record Listed/Removed once it actually appears/disappears on Creditinfo.');
        $this->redirect('/creditinfo/public-defaults/batches/' . $direction . '/' . $idInt);
    }
}
