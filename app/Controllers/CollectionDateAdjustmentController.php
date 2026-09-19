<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\CollectionDateAdjustment;
use App\Models\PayCyclePolicy;
use App\Services\CollectionDateAdjustmentService;

/**
 * The preview dashboard + Generate/Submit-for-Approval actions.
 * Approve/Reject on a submitted batch happen on THIS controller's own
 * routes (mirroring LoanWriteOffController exactly) rather than the
 * generic /approvals inbox, so this module's own status columns update
 * in the same click -- see ApprovalService's docblock on why (its
 * ApprovalCompleted event has no listener anywhere in the app today).
 */
class CollectionDateAdjustmentController extends Controller
{
    private CollectionDateAdjustment $adjustments;
    private PayCyclePolicy $policies;
    private CollectionDateAdjustmentService $service;

    public function __construct()
    {
        $this->adjustments = new CollectionDateAdjustment();
        $this->policies = new PayCyclePolicy();
        $this->service = new CollectionDateAdjustmentService();
    }

    public function index(): void
    {
        Auth::authorize('collection_date_adjustments.view');

        $filters = [
            'month' => trim($_GET['month'] ?? ''),
            'employer' => trim($_GET['employer'] ?? ''),
            'policy_id' => (int) ($_GET['policy_id'] ?? 0) ?: null,
            'reason' => trim($_GET['reason'] ?? ''),
            'approval_status' => trim($_GET['approval_status'] ?? ''),
            'collexia_status' => trim($_GET['collexia_status'] ?? ''),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $result = $this->adjustments->filtered($filters, $page, 25);

        $this->view('collection_date_adjustments/index', [
            'title' => 'Collection Date Adjustments',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 25,
            'filters' => $filters,
            'employers' => $this->adjustments->distinctEmployersInAdjustments(),
            'policyOptions' => $this->policies->allPolicies(),
        ]);
    }

    /** Manual trigger -- the same routine bin/generate_collection_date_adjustments.php would run automatically once scheduled. Idempotent: a same-day re-run with nothing changed creates zero rows. */
    public function generatePreview(): void
    {
        Auth::authorize('collection_date_adjustments.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collection-date-adjustments');
            return;
        }

        $through = new \DateTimeImmutable('+30 days');
        $result = $this->service->generateRollingPreview($through, Auth::user()['id'] ?? null);

        if ($result['batch_id'] === null) {
            Session::flash('success', 'Preview refreshed -- nothing new to review (no upcoming collection needs adjusting).');
        } else {
            Session::flash('success', 'Preview refreshed -- batch #' . $result['batch_id'] . ' created with ' . count($result['adjustment_ids']) . ' proposed adjustment(s).');
        }
        $this->redirect('/collection-date-adjustments');
    }

    public function submitForApproval(string $batchId): void
    {
        Auth::authorize('collection_date_adjustments.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collection-date-adjustments');
            return;
        }

        try {
            $this->service->submitForApproval((int) $batchId, Auth::user()['id'] ?? null);
            Session::flash('success', 'Batch #' . $batchId . ' submitted for approval.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect('/collection-date-adjustments');
    }

    public function approveBatch(string $batchId): void
    {
        Auth::authorize('collection_date_adjustments.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collection-date-adjustments');
            return;
        }

        $comments = trim($_POST['comments'] ?? '');
        try {
            $this->service->approve((int) $batchId, $comments !== '' ? $comments : null, Auth::user()['id'] ?? null);
            Session::flash('success', 'Batch #' . $batchId . ' approved.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect('/collection-date-adjustments');
    }

    public function rejectBatch(string $batchId): void
    {
        Auth::authorize('collection_date_adjustments.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/collection-date-adjustments');
            return;
        }

        $comments = trim($_POST['comments'] ?? '');
        if ($comments === '') {
            Session::flash('error', 'A reason is required to reject a batch.');
            $this->redirect('/collection-date-adjustments');
            return;
        }

        try {
            $this->service->reject((int) $batchId, $comments, Auth::user()['id'] ?? null);
            Session::flash('success', 'Batch #' . $batchId . ' rejected.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect('/collection-date-adjustments');
    }
}
