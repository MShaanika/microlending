<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\ApprovalRequest;
use App\Models\Borrower;
use App\Models\CreditinfoDispute;
use App\Models\CreditinfoPublicDefault;
use App\Models\CreditinfoPublicDefaultAction;
use App\Models\CreditinfoPublicDefaultNotice;
use App\Models\CreditinfoPublicDefaultSubmission;
use App\Models\Loan;
use App\Services\ApprovalService;
use App\Services\CreditinfoPublicDefaultService;

/**
 * Public Defaults workflow -- Listing and Removal (Individual), per the
 * signed Creditinfo Statement of Work. Architecturally separate from the
 * existing CBS module: never touches creditinfo_settings, CreditinfoClient,
 * or CreditinfoBureauClient. Maker-checker is enforced by the existing
 * generic Approval Engine (ApprovalService) -- see
 * database/creditinfo_public_defaults_manual_submission.sql's header
 * comment on the approval_policies seed for why that policy is treated as
 * mandatory here, unlike the optional write-off policy.
 *
 * Submission boundary: Creditinfo has confirmed in writing there is no
 * REST API for Public Defaults, only their own User Interface and (not
 * yet specified) SFTP. There is therefore no gateway class here making an
 * automated call -- recordListingSubmission()/confirmListed() (and the
 * removal equivalents) capture EVIDENCE of a human staff member
 * submitting through Creditinfo's own UI, via CreditinfoPublicDefaultSubmission.
 */
class CreditinfoPublicDefaultController extends Controller
{
    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024;

    private const REMOVABLE_STATUSES = ['Listed', 'Removal Required', 'Removal Rejected'];
    // Explicit allowlist, not "status doesn't start with 'Removal'" -- that
    // prefix check would miss 'Awaiting Manual Removal Submission', which
    // is a removal-phase status but doesn't start with the word "Removal".
    // Deliberately stops at 'Awaiting Manual Submission' -- once a listing
    // reaches 'Submitted via Creditinfo UI', it has already been submitted
    // to a real, external system; DesertLedger cancelling its own record
    // at that point would misrepresent what actually happened at Creditinfo.
    private const CANCELLABLE_LISTING_STATUSES = ['Draft', 'Pending Review', 'Awaiting Manual Submission'];
    private const RECORDABLE_LISTING_SUBMISSION_STATUSES = ['Awaiting Manual Submission'];
    private const CONFIRMABLE_LISTED_STATUSES = ['Submitted via Creditinfo UI'];
    private const RECORDABLE_REMOVAL_SUBMISSION_STATUSES = ['Awaiting Manual Removal Submission'];
    private const CONFIRMABLE_REMOVED_STATUSES = ['Removal Submitted via Creditinfo UI'];

    private CreditinfoPublicDefault $defaults;
    private CreditinfoPublicDefaultAction $actions;
    private CreditinfoPublicDefaultNotice $notices;
    private CreditinfoPublicDefaultSubmission $submissions;
    private CreditinfoPublicDefaultService $service;
    private Loan $loans;
    private Borrower $borrowers;

    public function __construct()
    {
        $this->defaults = new CreditinfoPublicDefault();
        $this->actions = new CreditinfoPublicDefaultAction();
        $this->notices = new CreditinfoPublicDefaultNotice();
        $this->submissions = new CreditinfoPublicDefaultSubmission();
        $this->service = new CreditinfoPublicDefaultService();
        $this->loans = new Loan();
        $this->borrowers = new Borrower();
    }

    /** Same convention as CreditinfoAssessmentController::assertBranchAccess() -- works for a $pd row or a $loan row, both of which carry branch_id. */
    private function assertBranchAccess(?array $record): void
    {
        if (!$record || Auth::isSuperAdmin() || $record['branch_id'] === null) {
            return;
        }
        if ((int) $record['branch_id'] !== (int) Auth::branchId()) {
            Session::flash('error', 'Record not found.');
            $this->redirect('/creditinfo/public-defaults');
            exit;
        }
    }

    /**
     * Item 11: lazily transitions any 'Listed' default whose loan has since
     * reached loan_status = 'Completed' into 'Removal Required'. Never
     * calls Creditinfo -- only flips DesertLedger's own status and logs it,
     * so staff see the prompt next time they open the dashboard. Idempotent:
     * settlementRemovalCandidates() only ever returns rows still 'Listed'.
     */
    private function syncSettlementTriggers(): void
    {
        foreach ($this->defaults->settlementRemovalCandidates() as $row) {
            $this->defaults->updateFields((int) $row['id'], ['status' => 'Removal Required', 'removal_trigger_source' => 'loan_settled_auto_detected']);
            $this->actions->log((int) $row['id'], 'PUBLIC_DEFAULT_REMOVAL_REQUIRED_DETECTED', null, 'Listed', 'Removal Required', 'Loan ' . $row['loan_no'] . ' is now fully settled.');
            Audit::log('Update', 'Creditinfo', 'Public default ' . $row['listing_reference'] . ' flagged Removal Required -- loan ' . $row['loan_no'] . ' fully settled', [], null);
        }
    }

    public function dashboard(): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $this->syncSettlementTriggers();

        $this->view('creditinfo/public_defaults/dashboard', [
            'title' => 'Public Defaults Dashboard',
            'counts' => $this->defaults->complianceCounts(),
            'usage' => $this->service->usageThisMonth(),
            'removalRequired' => $this->defaults->removalRequired(),
            'sftpSpecificationAwaited' => true,
        ]);
    }

    public function compliance(): void
    {
        Auth::authorize('creditinfo.public_defaults.view_audit');
        $this->syncSettlementTriggers();
        $counts = $this->defaults->complianceCounts();

        $this->view('creditinfo/public_defaults/compliance', [
            'title' => 'Public Default Compliance',
            'counts' => $counts,
            'disputesBlocking' => (new CreditinfoDispute())->countOpen(),
            'recentSubmissions' => $this->submissions->recent(20),
        ]);
    }

    // ---------------------------------------------------------------
    // Eligibility review (item 5)
    // ---------------------------------------------------------------

    public function eligibilitySearch(): void
    {
        Auth::authorize('creditinfo.public_defaults.create_listing');
        $query = trim((string) ($_GET['q'] ?? ''));
        $branchId = Auth::isSuperAdmin() ? null : Auth::branchId();
        $this->view('creditinfo/public_defaults/eligibility_search', [
            'title' => 'Public Default Eligibility Review',
            'query' => $query,
            // Biased toward loans currently in arrears -- the natural pool
            // for a Public Default review -- but a direct loan-number/name
            // search still finds any loan, since staff may already know
            // exactly which one they mean.
            'results' => $query !== '' ? $this->loans->paginated($query, '', 20, $branchId) : $this->loans->paginated('', 'Arrear', 20, $branchId),
        ]);
    }

    public function eligibility(string $loanId): void
    {
        Auth::authorize('creditinfo.public_defaults.create_listing');
        $loan = $this->loans->find((int) $loanId);
        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/creditinfo/public-defaults/eligibility');
            return;
        }
        $this->assertBranchAccess($loan);
        $borrower = $this->borrowers->find((int) $loan['borrower_id']);

        $this->view('creditinfo/public_defaults/eligibility', [
            'title' => 'Public Default Eligibility Review',
            'loan' => $loan,
            'borrower' => $borrower,
            'result' => $this->service->evaluateEligibility($loan, $borrower),
        ]);
    }

    // ---------------------------------------------------------------
    // Listing workflow (item 2)
    // ---------------------------------------------------------------

    public function listingIndex(): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $branchId = Auth::isSuperAdmin() ? null : Auth::branchId();
        $this->view('creditinfo/public_defaults/listing_requests/index', [
            'title' => 'Public Default Listing Requests',
            'result' => $this->defaults->listingQueue($status !== '' ? $status : null, $branchId, $page),
            'status' => $status,
            'page' => $page,
        ]);
    }

    public function listingCreate(string $loanId): void
    {
        Auth::authorize('creditinfo.public_defaults.create_listing');
        $loan = $this->loans->find((int) $loanId);
        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/creditinfo/public-defaults/eligibility');
            return;
        }
        $this->assertBranchAccess($loan);
        $borrower = $this->borrowers->find((int) $loan['borrower_id']);
        $result = $this->service->evaluateEligibility($loan, $borrower);

        if ($result['eligibility_status'] === 'Blocked') {
            Session::flash('error', 'PUBLIC DEFAULT LISTING BLOCKED — ' . implode(' ', $result['blocking_reasons']));
            $this->redirect('/creditinfo/public-defaults/eligibility/' . $loanId);
            return;
        }

        $this->view('creditinfo/public_defaults/listing_requests/create', [
            'title' => 'Create Public Default Listing Request',
            'loan' => $loan,
            'borrower' => $borrower,
            'result' => $result,
            'errors' => [],
        ]);
    }

    public function listingStore(): void
    {
        Auth::authorize('creditinfo.public_defaults.create_listing');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/eligibility');
            return;
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);
        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/creditinfo/public-defaults/eligibility');
            return;
        }
        $this->assertBranchAccess($loan);
        $borrower = $this->borrowers->find((int) $loan['borrower_id']);

        // Re-checked server-side, never trusted from the create-form GET --
        // this is the same evaluateEligibility() call, run again now.
        $result = $this->service->evaluateEligibility($loan, $borrower);
        if ($result['eligibility_status'] === 'Blocked') {
            Session::flash('error', 'PUBLIC DEFAULT LISTING BLOCKED — ' . implode(' ', $result['blocking_reasons']));
            $this->redirect('/creditinfo/public-defaults/eligibility/' . $loanId);
            return;
        }

        $listingReason = trim($_POST['listing_reason'] ?? '');
        if ($listingReason === '') {
            $this->view('creditinfo/public_defaults/listing_requests/create', [
                'title' => 'Create Public Default Listing Request',
                'loan' => $loan,
                'borrower' => $borrower,
                'result' => $result,
                'errors' => ['listing_reason' => 'A listing reason is required.'],
            ]);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $reference = generate_reference('PD');

        // Locks the loan row for the duration of the check-then-insert below
        // so two concurrent submissions for the same loan can't both pass
        // hasActiveForLoan() and both create a Draft -- the second request
        // blocks here until the first commits, then sees the row the first
        // just created and is correctly refused.
        try {
            $id = $this->defaults->transaction(function () use ($loan, $borrower, $result, $listingReason, $userId, $reference) {
                $this->loans->findForUpdate((int) $loan['id']);
                if ($this->defaults->hasActiveForLoan((int) $loan['id'])) {
                    throw new \RuntimeException('This loan already has a listing request in progress or an active public default.');
                }
                return $this->defaults->create([
                    'listing_reference' => $reference,
                    'borrower_id' => $borrower['id'],
                    'loan_id' => $loan['id'],
                    'loan_application_id' => $loan['application_id'] ?: null,
                    'branch_id' => $loan['branch_id'] ?: null,
                    'default_type' => 'Individual',
                    'outstanding_amount' => $result['outstanding_balance'],
                    'original_amount' => $loan['principal_amount'],
                    'default_date' => date('Y-m-d'),
                    'days_in_arrears_at_listing' => $result['days_in_arrears'],
                    'listing_reason' => $listingReason,
                    'status' => 'Draft',
                    'listing_requested_by' => $userId,
                    'listing_requested_at' => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/creditinfo/public-defaults/eligibility/' . $loanId);
            return;
        }

        $this->actions->log($id, 'PUBLIC_DEFAULT_DRAFT_CREATED', $userId, null, 'Draft', $listingReason);
        $noticeSaved = $this->storeNotice($id, $userId);
        Audit::log('Create', 'Creditinfo', 'Created Public Default draft ' . $reference . ' for loan ' . $loan['loan_no'], [], $reference);

        if (!$noticeSaved) {
            Session::flash('error', 'Draft created (' . $reference . '), but the notice was not saved -- a notice method and date must both be set. Add it from the draft screen below.');
        } else {
            Session::flash('success', 'Draft created (' . $reference . '). Submit it for review when ready.');
        }
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    public function listingSubmitForReview(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.create_listing');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || $pd['status'] !== 'Draft') {
            Session::flash('error', 'Only a Draft listing can be submitted for review.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        try {
            $this->service->assertNoOpenDispute((int) $pd['borrower_id']);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $userId = (int) (Auth::user()['id'] ?? 0);
        $approvalId = ApprovalService::request('public_default_listing_approval', [
            'resource_id' => $id,
            'maker_user_id' => $userId,
            'title' => 'Public Default Listing ' . $pd['listing_reference'] . ' (' . $pd['borrower_name'] . ')',
            'amount' => $pd['outstanding_amount'],
            'reason' => $pd['listing_reason'],
        ]);

        if ($approvalId === null) {
            // Mandatory dual control (item 7) -- unlike write-offs, there is
            // no single-permission fallback here. A missing/inactive policy
            // is a configuration error, not a reason to skip the checker.
            Session::flash('error', 'Public Default approval policy is not active. Contact an administrator before submitting for review.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $this->defaults->updateFields($id, ['status' => 'Pending Review']);
        $this->actions->log($id, 'PUBLIC_DEFAULT_LISTING_REQUESTED', $userId, 'Draft', 'Pending Review');
        Audit::log('Update', 'Creditinfo', 'Submitted Public Default ' . $pd['listing_reference'] . ' for approval', [], $pd['listing_reference']);

        Session::flash('success', 'Submitted for approval.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    public function listingApprove(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.approve_listing');
        $this->decideListing($id, true);
    }

    public function listingReject(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.approve_listing');
        $this->decideListing($id, false);
    }

    private function decideListing(string $idStr, bool $approve): void
    {
        $id = (int) $idStr;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || $pd['status'] !== 'Pending Review') {
            Session::flash('error', 'Only a listing Pending Review can be decided.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $comments = trim((string) ($_POST['comments'] ?? ''));
        $approvalRequest = (new ApprovalRequest())->findPendingByResource('Creditinfo', 'public_default_listing', $id);
        if (!$approvalRequest) {
            Session::flash('error', 'No open approval request found for this listing.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $userId = (int) (Auth::user()['id'] ?? 0);

        try {
            if ($approve) {
                ApprovalService::approve((int) $approvalRequest['id'], $comments !== '' ? $comments : null);
            } else {
                // Never substitute a placeholder for a blank comment --
                // ApprovalService::reject() enforces "a reason is required
                // to reject a request" and that guard must stay reachable.
                ApprovalService::reject((int) $approvalRequest['id'], $comments);
            }
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        if ($approve) {
            $this->defaults->updateFields($id, [
                'status' => 'Awaiting Manual Submission',
                'listing_approved_by' => $userId,
                'listing_approved_at' => date('Y-m-d H:i:s'),
            ]);
            $this->actions->log($id, 'PUBLIC_DEFAULT_LISTING_APPROVED', $userId, 'Pending Review', 'Awaiting Manual Submission', $comments ?: null);
            Audit::log('Approve', 'Creditinfo', 'Approved Public Default listing ' . $pd['listing_reference'], [], $pd['listing_reference']);
            Session::flash('success', 'Listing approved. Submit it via Creditinfo\'s User Interface (SFTP not yet available), then record the submission below.');
        } else {
            $this->defaults->updateFields($id, ['status' => 'Rejected']);
            $this->actions->log($id, 'PUBLIC_DEFAULT_LISTING_REJECTED', $userId, 'Pending Review', 'Rejected', $comments);
            Audit::log('Reject', 'Creditinfo', 'Rejected Public Default listing ' . $pd['listing_reference'] . ': ' . $comments, [], $pd['listing_reference']);
            Session::flash('success', 'Listing rejected.');
        }

        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    /**
     * Item 5: records that a staff member manually submitted this listing
     * via Creditinfo's own User Interface -- there is no REST call here.
     * Captures submitted_by/submitted_at (both automatic), an optional
     * Creditinfo reference, optional supporting evidence, and notes, per
     * the vendor's confirmed manual workflow. Does NOT itself mean the
     * default is live -- confirmListed() is the separate, deliberate
     * confirmation step once staff has verified it actually appears on
     * Creditinfo.
     */
    public function recordListingSubmission(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.submit');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || !in_array($pd['status'], self::RECORDABLE_LISTING_SUBMISSION_STATUSES, true)) {
            Session::flash('error', 'Only a listing Awaiting Manual Submission can be recorded as submitted.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $userId = (int) (Auth::user()['id'] ?? 0);
        $creditinfoReference = trim((string) ($_POST['creditinfo_reference'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $evidencePath = $this->storeSubmissionEvidence($id);

        $this->submissions->create([
            'public_default_id' => $id,
            'direction' => 'listing',
            'method' => 'manual_ui',
            'submitted_by' => $userId,
            'submitted_at' => date('Y-m-d H:i:s'),
            'creditinfo_reference' => $creditinfoReference,
            'evidence_document' => $evidencePath,
            'notes' => $notes,
        ]);

        $this->defaults->updateFields($id, [
            'status' => 'Submitted via Creditinfo UI',
            'creditinfo_reference' => $creditinfoReference,
        ]);
        $this->actions->log($id, 'PUBLIC_DEFAULT_SUBMITTED', $userId, 'Awaiting Manual Submission', 'Submitted via Creditinfo UI', $notes, $creditinfoReference);
        Audit::log('Update', 'Creditinfo', 'Recorded manual Creditinfo UI submission for Public Default ' . $pd['listing_reference'], [], $pd['listing_reference']);

        Session::flash('success', 'Submission recorded. Confirm Listed once it actually appears live on Creditinfo.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    /** The separate, deliberate confirmation that a recorded submission is actually live on Creditinfo -- never inferred automatically from recordListingSubmission() alone. */
    public function confirmListed(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.submit');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || !in_array($pd['status'], self::CONFIRMABLE_LISTED_STATUSES, true)) {
            Session::flash('error', 'Only a listing Submitted via Creditinfo UI can be confirmed Listed.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $userId = (int) (Auth::user()['id'] ?? 0);
        $this->defaults->updateFields($id, ['status' => 'Listed', 'listed_at' => date('Y-m-d H:i:s')]);
        $this->actions->log($id, 'PUBLIC_DEFAULT_LISTED', $userId, 'Submitted via Creditinfo UI', 'Listed');
        Audit::log('Update', 'Creditinfo', 'Confirmed Public Default ' . $pd['listing_reference'] . ' Listed', [], $pd['listing_reference']);

        Session::flash('success', 'Confirmed Listed.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    public function listingCancel(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.approve_listing');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || !in_array($pd['status'], self::CANCELLABLE_LISTING_STATUSES, true)) {
            Session::flash('error', 'This listing cannot be cancelled from its current status.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $reason = trim((string) ($_POST['reason'] ?? ''));
        $userId = (int) (Auth::user()['id'] ?? 0);
        $this->defaults->updateFields($id, ['status' => 'Cancelled']);
        $this->actions->log($id, 'PUBLIC_DEFAULT_CANCELLED', $userId, $pd['status'], 'Cancelled', $reason ?: null);
        Audit::log('Update', 'Creditinfo', 'Cancelled Public Default listing ' . $pd['listing_reference'], [], $pd['listing_reference']);

        Session::flash('success', 'Listing cancelled.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    // ---------------------------------------------------------------
    // Removal workflow (item 3) -- always against an existing
    // creditinfo_public_defaults row; there is no route that can create a
    // removal request without one, so an orphan removal is impossible.
    // ---------------------------------------------------------------

    public function removalIndex(): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $branchId = Auth::isSuperAdmin() ? null : Auth::branchId();
        $this->view('creditinfo/public_defaults/removal_requests/index', [
            'title' => 'Public Default Removal Requests',
            'result' => $this->defaults->removalQueue($status !== '' ? $status : null, $branchId, $page),
            'status' => $status,
            'page' => $page,
        ]);
    }

    public function removalCreate(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.create_removal');
        $pd = $this->defaults->find((int) $id);
        if (!$pd || !in_array($pd['status'], self::REMOVABLE_STATUSES, true)) {
            Session::flash('error', 'A removal request can only be created for an active listing, a Removal Required prompt, or a previously rejected removal.');
            $this->redirect('/creditinfo/public-defaults');
            return;
        }
        $this->assertBranchAccess($pd);

        $this->view('creditinfo/public_defaults/removal_requests/create', [
            'title' => 'Create Public Default Removal Request',
            'pd' => $pd,
        ]);
    }

    private const REMOVAL_REASON_CATEGORIES = [
        'Loan fully settled', 'Written settlement agreement completed', 'Listing made in error',
        'Duplicate listing', 'Dispute resolved in borrower favour', 'Compliance instruction',
        'Creditinfo instruction', 'Other authorised reason',
    ];

    public function removalStore(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.create_removal');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || !in_array($pd['status'], self::REMOVABLE_STATUSES, true)) {
            Session::flash('error', 'This default is not in a state that allows a removal request.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $category = trim((string) ($_POST['removal_reason_category'] ?? ''));
        $reason = trim((string) ($_POST['removal_reason'] ?? ''));
        if (!in_array($category, self::REMOVAL_REASON_CATEGORIES, true) || $reason === '') {
            Session::flash('error', 'Select a removal reason category and provide details.');
            $this->redirect('/creditinfo/public-defaults/' . $id . '/removal/create');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $fromStatus = $pd['status'];
        $this->defaults->updateFields($id, [
            'status' => 'Removal Pending Review',
            'removal_reason' => $reason,
            'removal_reason_category' => $category,
            'removal_requested_by' => $userId,
            'removal_requested_at' => date('Y-m-d H:i:s'),
            // A resubmission after a rejection starts a clean removal cycle.
            'removal_approved_by' => null,
            'removal_approved_at' => null,
        ]);
        $this->actions->log($id, 'PUBLIC_DEFAULT_REMOVAL_REQUESTED', $userId, $fromStatus, 'Removal Pending Review', $reason);

        $approvalId = ApprovalService::request('public_default_removal_approval', [
            'resource_id' => $id,
            'maker_user_id' => $userId,
            'title' => 'Public Default Removal ' . $pd['listing_reference'] . ' (' . $pd['borrower_name'] . ')',
            'amount' => $pd['outstanding_amount'],
            'reason' => $reason,
        ]);

        if ($approvalId === null) {
            Session::flash('error', 'Public Default removal approval policy is not active. Contact an administrator.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        Audit::log('Update', 'Creditinfo', 'Requested removal of Public Default ' . $pd['listing_reference'], [], $pd['listing_reference']);
        Session::flash('success', 'Removal requested. It needs approval before submission.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    public function removalApprove(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.approve_removal');
        $this->decideRemoval($id, true);
    }

    public function removalReject(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.approve_removal');
        $this->decideRemoval($id, false);
    }

    private function decideRemoval(string $idStr, bool $approve): void
    {
        $id = (int) $idStr;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || $pd['status'] !== 'Removal Pending Review') {
            Session::flash('error', 'Only a removal Pending Review can be decided.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $comments = trim((string) ($_POST['comments'] ?? ''));
        $approvalRequest = (new ApprovalRequest())->findPendingByResource('Creditinfo', 'public_default_removal', $id);
        if (!$approvalRequest) {
            Session::flash('error', 'No open approval request found for this removal.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $userId = (int) (Auth::user()['id'] ?? 0);

        try {
            if ($approve) {
                ApprovalService::approve((int) $approvalRequest['id'], $comments !== '' ? $comments : null);
            } else {
                // See decideListing() -- never substitute a placeholder for a
                // blank comment; ApprovalService::reject()'s mandatory-reason
                // guard must stay reachable.
                ApprovalService::reject((int) $approvalRequest['id'], $comments);
            }
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        if ($approve) {
            $this->defaults->updateFields($id, [
                'status' => 'Awaiting Manual Removal Submission',
                'removal_approved_by' => $userId,
                'removal_approved_at' => date('Y-m-d H:i:s'),
            ]);
            $this->actions->log($id, 'PUBLIC_DEFAULT_REMOVAL_APPROVED', $userId, 'Removal Pending Review', 'Awaiting Manual Removal Submission', $comments ?: null);
            Audit::log('Approve', 'Creditinfo', 'Approved removal of Public Default ' . $pd['listing_reference'], [], $pd['listing_reference']);
            Session::flash('success', 'Removal approved. Submit it via Creditinfo\'s User Interface (SFTP not yet available), then record the submission below.');
        } else {
            $this->defaults->updateFields($id, ['status' => 'Removal Rejected']);
            $this->actions->log($id, 'PUBLIC_DEFAULT_REMOVAL_REJECTED', $userId, 'Removal Pending Review', 'Removal Rejected', $comments);
            Audit::log('Reject', 'Creditinfo', 'Rejected removal of Public Default ' . $pd['listing_reference'] . ': ' . $comments, [], $pd['listing_reference']);
            Session::flash('success', 'Removal rejected.');
        }

        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    /** Removal equivalent of recordListingSubmission() -- see its docblock. */
    public function recordRemovalSubmission(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.submit');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || !in_array($pd['status'], self::RECORDABLE_REMOVAL_SUBMISSION_STATUSES, true)) {
            Session::flash('error', 'Only a removal Awaiting Manual Removal Submission can be recorded as submitted.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $userId = (int) (Auth::user()['id'] ?? 0);
        $creditinfoReference = trim((string) ($_POST['creditinfo_reference'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $evidencePath = $this->storeSubmissionEvidence($id);

        $this->submissions->create([
            'public_default_id' => $id,
            'direction' => 'removal',
            'method' => 'manual_ui',
            'submitted_by' => $userId,
            'submitted_at' => date('Y-m-d H:i:s'),
            'creditinfo_reference' => $creditinfoReference,
            'evidence_document' => $evidencePath,
            'notes' => $notes,
        ]);

        $this->defaults->updateFields($id, ['status' => 'Removal Submitted via Creditinfo UI']);
        $this->actions->log($id, 'PUBLIC_DEFAULT_REMOVAL_SUBMITTED', $userId, 'Awaiting Manual Removal Submission', 'Removal Submitted via Creditinfo UI', $notes, $creditinfoReference);
        Audit::log('Update', 'Creditinfo', 'Recorded manual Creditinfo UI removal submission for Public Default ' . $pd['listing_reference'], [], $pd['listing_reference']);

        Session::flash('success', 'Removal submission recorded. Confirm Removed once it actually disappears from Creditinfo.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    /** Removal equivalent of confirmListed() -- see its docblock. */
    public function confirmRemoved(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.submit');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd || !in_array($pd['status'], self::CONFIRMABLE_REMOVED_STATUSES, true)) {
            Session::flash('error', 'Only a removal Submitted via Creditinfo UI can be confirmed Removed.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }
        $this->assertBranchAccess($pd);

        $userId = (int) (Auth::user()['id'] ?? 0);
        $this->defaults->updateFields($id, ['status' => 'Removed', 'removed_at' => date('Y-m-d H:i:s')]);
        $this->actions->log($id, 'PUBLIC_DEFAULT_REMOVED', $userId, 'Removal Submitted via Creditinfo UI', 'Removed');
        Audit::log('Update', 'Creditinfo', 'Confirmed removal of Public Default ' . $pd['listing_reference'], [], $pd['listing_reference']);

        Session::flash('success', 'Confirmed Removed.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    // ---------------------------------------------------------------
    // Register / History / Detail / Notice (items 8, 9, 10)
    // ---------------------------------------------------------------

    public function register(): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $this->view('creditinfo/public_defaults/register', [
            'title' => 'Active Public Defaults Register',
            'result' => $this->defaults->activeRegister($this->filtersFromQuery(), $page),
            'filters' => $this->filtersFromQuery(),
        ]);
    }

    public function history(): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $this->view('creditinfo/public_defaults/history', [
            'title' => 'Public Defaults History',
            'result' => $this->defaults->history($this->filtersFromQuery(), $page),
            'filters' => $this->filtersFromQuery(),
        ]);
    }

    private function filtersFromQuery(): array
    {
        $filters = array_filter([
            'status' => trim((string) ($_GET['status'] ?? '')),
            'amount_min' => $_GET['amount_min'] ?? null,
            'amount_max' => $_GET['amount_max'] ?? null,
            'days_in_arrears_min' => $_GET['days_in_arrears_min'] ?? null,
            'listed_from' => $_GET['listed_from'] ?? null,
            'listed_to' => $_GET['listed_to'] ?? null,
            'removed_from' => $_GET['removed_from'] ?? null,
            'removed_to' => $_GET['removed_to'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // Enforced from the session, never from the query string -- a
        // branch-scoped user cannot widen this by editing the URL.
        if (!Auth::isSuperAdmin()) {
            $filters['branch_id'] = Auth::branchId();
        }

        return $filters;
    }

    /** Item 10: export of internal operational records only -- never a raw Creditinfo response, since none is ever stored (the gateway always throws before any response can exist). */
    public function registerExport(): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $this->exportCsv($this->defaults->activeRegister($this->filtersFromQuery(), 1, 5000)['rows'], 'public_defaults_active_register.csv');
    }

    public function historyExport(): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $this->exportCsv($this->defaults->history($this->filtersFromQuery(), 1, 5000)['rows'], 'public_defaults_history.csv');
    }

    private function exportCsv(array $rows, string $filename): void
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reference', 'Borrower', 'Loan', 'ID Number', 'Default Date', 'Original Amount', 'Outstanding', 'Listing Date', 'Status', 'Creditinfo Reference']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['listing_reference'], $r['borrower_name'], $r['loan_no'],
                substr((string) $r['id_number'], 0, 4) . str_repeat('*', max(0, strlen((string) $r['id_number']) - 4)),
                $r['default_date'], $r['original_amount'], $r['outstanding_amount'],
                $r['listed_at'], $r['status'], $r['creditinfo_reference'],
            ]);
        }
        fclose($out);
        exit;
    }

    public function show(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $pd = $this->defaults->find((int) $id);
        if (!$pd) {
            Session::flash('error', 'Public default record not found.');
            $this->redirect('/creditinfo/public-defaults');
            return;
        }
        $this->assertBranchAccess($pd);

        $this->view('creditinfo/public_defaults/show', [
            'title' => 'Public Default ' . $pd['listing_reference'],
            'pd' => $pd,
            'notices' => $this->notices->forPublicDefault((int) $id),
            'submissions' => $this->submissions->forPublicDefault((int) $id),
            'timeline' => $this->actions->timeline((int) $id),
            'reasonCategories' => self::REMOVAL_REASON_CATEGORIES,
        ]);
    }

    public function recordNotice(string $id): void
    {
        Auth::authorize('creditinfo.public_defaults.create_listing');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $pd = $this->defaults->find($id);
        if (!$pd) {
            Session::flash('error', 'Public default record not found.');
            $this->redirect('/creditinfo/public-defaults');
            return;
        }
        $this->assertBranchAccess($pd);

        $userId = Auth::user()['id'] ?? null;
        if (!$this->storeNotice($id, $userId)) {
            Session::flash('error', 'Select a notice method and date.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        Audit::log('Create', 'Creditinfo', 'Recorded borrower notice for Public Default ' . $pd['listing_reference'], [], $pd['listing_reference']);
        Session::flash('success', 'Notice recorded.');
        $this->redirect('/creditinfo/public-defaults/' . $id);
    }

    private function storeNotice(int $publicDefaultId, ?int $userId): bool
    {
        $method = trim((string) ($_POST['notice_method'] ?? ''));
        $date = trim((string) ($_POST['notice_date'] ?? ''));
        if ($method === '' && $date === '' && empty($_FILES['notice_document']['name'])) {
            return true; // Nothing submitted this round -- not an error, just nothing to record yet.
        }
        if ($method === '' || $date === '') {
            return false;
        }

        $documentPath = $this->storeNoticeDocument($publicDefaultId);

        $this->notices->create([
            'public_default_id' => $publicDefaultId,
            'notice_required' => 1,
            'notice_date' => $date,
            'notice_method' => $method,
            'notice_reference' => trim((string) ($_POST['notice_reference'] ?? '')) ?: null,
            'notice_document' => $documentPath,
            'notice_sent_by' => $userId,
        ]);

        return true;
    }

    private function storeNoticeDocument(int $publicDefaultId): ?string
    {
        $file = $_FILES['notice_document'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > self::MAX_DOCUMENT_SIZE) {
            return null;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
            return null;
        }

        $targetDir = STORAGE_PATH . '/uploads/public_defaults/' . $publicDefaultId;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $storedName = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $targetDir . '/' . $storedName)) {
            return null;
        }

        return 'uploads/public_defaults/' . $publicDefaultId . '/' . $storedName;
    }

    /** Optional supporting evidence for a manual Creditinfo UI submission (e.g. a screenshot) -- same upload discipline as storeNoticeDocument(), never required. */
    private function storeSubmissionEvidence(int $publicDefaultId): ?string
    {
        $file = $_FILES['evidence_document'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > self::MAX_DOCUMENT_SIZE) {
            return null;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
            return null;
        }

        $targetDir = STORAGE_PATH . '/uploads/public_defaults/' . $publicDefaultId . '/submissions';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $storedName = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $targetDir . '/' . $storedName)) {
            return null;
        }

        return 'uploads/public_defaults/' . $publicDefaultId . '/submissions/' . $storedName;
    }

    public function downloadNoticeDocument(string $id, string $noticeId): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $pd = $this->defaults->find((int) $id);
        if (!$pd) {
            Session::flash('error', 'Public default record not found.');
            $this->redirect('/creditinfo/public-defaults');
            return;
        }
        $this->assertBranchAccess($pd);

        $notices = $this->notices->forPublicDefault((int) $id);
        $notice = null;
        foreach ($notices as $n) {
            if ((int) $n['id'] === (int) $noticeId) {
                $notice = $n;
                break;
            }
        }

        if (!$notice || empty($notice['notice_document'])) {
            Session::flash('error', 'Notice document not found.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $notice['notice_document'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    public function downloadSubmissionDocument(string $id, string $submissionId): void
    {
        Auth::authorize('creditinfo.public_defaults.view');
        $pd = $this->defaults->find((int) $id);
        if (!$pd) {
            Session::flash('error', 'Public default record not found.');
            $this->redirect('/creditinfo/public-defaults');
            return;
        }
        $this->assertBranchAccess($pd);

        $submissions = $this->submissions->forPublicDefault((int) $id);
        $submission = null;
        foreach ($submissions as $s) {
            if ((int) $s['id'] === (int) $submissionId) {
                $submission = $s;
                break;
            }
        }

        if (!$submission || empty($submission['evidence_document'])) {
            Session::flash('error', 'Submission evidence document not found.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $submission['evidence_document'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/creditinfo/public-defaults/' . $id);
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
