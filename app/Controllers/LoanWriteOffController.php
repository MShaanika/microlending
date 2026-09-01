<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Idempotency;
use App\Core\IdempotencyBusyException;
use App\Core\IdempotencyReplayException;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\ApprovalRequest;
use App\Models\BadDebt;
use App\Models\BadDebtProvision;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Models\LoanWriteOff;
use App\Models\SystemSetting;
use App\Services\ApprovalService;
use App\Services\ArrearsService;

class LoanWriteOffController extends Controller
{
    private LoanWriteOff $writeOffs;
    private LoanRecovery $recoveries;
    private BadDebt $badDebts;
    private BadDebtProvision $provisions;
    private Loan $loans;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;
    private SystemSetting $settings;

    public function __construct()
    {
        $this->writeOffs = new LoanWriteOff();
        $this->recoveries = new LoanRecovery();
        $this->badDebts = new BadDebt();
        $this->provisions = new BadDebtProvision();
        $this->loans = new Loan();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
        $this->settings = new SystemSetting();
    }

    /** ALLOWANCE / DIRECT / SELECT_AT_WRITE_OFF -- see LOAN_WRITE_OFF_METHOD setting. */
    private function configuredWriteOffMethod(): string
    {
        return $this->settings->get('LOAN_WRITE_OFF_METHOD', 'SELECT_AT_WRITE_OFF') ?: 'SELECT_AT_WRITE_OFF';
    }

    public function index(): void
    {
        Auth::authorize('accounting.writeoffs');
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('accounting/loan_write_offs/index', [
            'title' => 'Loan Write-Offs',
            'writeOffs' => $this->writeOffs->paginated($status),
            'status' => $status,
        ]);
    }

    public function create(string $badDebtId): void
    {
        Auth::authorize('accounting.writeoffs');
        $badDebt = $this->badDebts->find((int) $badDebtId);

        if (!$badDebt) {
            Session::flash('error', 'Bad debt record not found.');
            $this->redirect('/accounting/bad-debt-provisions');
            return;
        }

        if ($this->writeOffs->hasActiveForLoan((int) $badDebt['loan_id'])) {
            Session::flash('error', 'This loan already has a write-off in progress or posted. Reverse it first if a new one is genuinely needed.');
            $this->redirect('/accounting/bad-debt-provisions');
            return;
        }

        $outstanding = ArrearsService::loanOutstanding((int) $badDebt['loan_id'], date('Y-m-d'));
        $provisionAmount = $this->provisions->provisionForLoan((int) $badDebt['loan_id']);
        $configuredMethod = $this->configuredWriteOffMethod();

        $this->view('accounting/loan_write_offs/create', [
            'title' => 'Write Off Loan ' . $badDebt['loan_no'],
            'badDebt' => $badDebt,
            'outstandingBalance' => $outstanding['outstanding_balance'],
            'provisionAmount' => $provisionAmount,
            // Allowance requires the loan's provision to fully cover the
            // outstanding balance -- never a partial draw-down mixed with a
            // new expense (that would post both methods against the same
            // balance, which the spec explicitly forbids).
            'allowanceEligible' => $provisionAmount >= $outstanding['outstanding_balance'],
            'configuredMethod' => $configuredMethod,
            'requiresMethodChoice' => $configuredMethod === 'SELECT_AT_WRITE_OFF',
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('accounting.writeoffs');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/loan-write-offs');
            return;
        }

        $badDebtId = (int) ($_POST['bad_debt_id'] ?? 0);
        $badDebt = $this->badDebts->find($badDebtId);
        $reason = trim($_POST['reason'] ?? '');

        if (!$badDebt) {
            Session::flash('error', 'Bad debt record not found.');
            $this->redirect('/accounting/bad-debt-provisions');
            return;
        }

        $outstanding = ArrearsService::loanOutstanding((int) $badDebt['loan_id'], date('Y-m-d'));
        $provisionAmount = $this->provisions->provisionForLoan((int) $badDebt['loan_id']);
        $allowanceEligible = $provisionAmount >= $outstanding['outstanding_balance'];
        $configuredMethod = $this->configuredWriteOffMethod();

        $method = $configuredMethod === 'SELECT_AT_WRITE_OFF'
            ? trim((string) ($_POST['write_off_method'] ?? ''))
            : ($configuredMethod === 'DIRECT' ? 'Direct' : 'Allowance');

        $methodError = null;
        if (!in_array($method, ['Allowance', 'Direct'], true)) {
            $methodError = 'Select a write-off method.';
        } elseif ($method === 'Allowance' && !$allowanceEligible) {
            $methodError = 'The Allowance method needs the provision to fully cover the outstanding balance. Fund the provision first, or use Direct Write-Off.';
        }

        if ($reason === '' || $methodError !== null) {
            $this->view('accounting/loan_write_offs/create', [
                'title' => 'Write Off Loan ' . $badDebt['loan_no'],
                'badDebt' => $badDebt,
                'outstandingBalance' => $outstanding['outstanding_balance'],
                'provisionAmount' => $provisionAmount,
                'allowanceEligible' => $allowanceEligible,
                'configuredMethod' => $configuredMethod,
                'requiresMethodChoice' => $configuredMethod === 'SELECT_AT_WRITE_OFF',
                'errors' => array_filter([
                    'reason' => $reason === '' ? 'A reason is required to request a write-off.' : null,
                    'write_off_method' => $methodError,
                ]),
            ]);
            return;
        }

        if ($this->writeOffs->hasActiveForLoan((int) $badDebt['loan_id'])) {
            Session::flash('error', 'This loan already has a write-off in progress or posted.');
            $this->redirect('/accounting/bad-debt-provisions');
            return;
        }

        $writeOffNo = generate_reference('WO');
        $writeOffId = $this->writeOffs->create([
            'loan_id' => $badDebt['loan_id'],
            'borrower_id' => $badDebt['borrower_id'],
            'branch_id' => $badDebt['branch_id'],
            'write_off_method' => $method,
            'bad_debt_id' => $badDebtId,
            'write_off_no' => $writeOffNo,
            'write_off_date' => date('Y-m-d'),
            'loan_amount' => $outstanding['outstanding_balance'],
            'total_paid' => 0,
            'outstanding_balance' => $outstanding['outstanding_balance'],
            // Reflects the chosen method, not a hybrid split: Allowance
            // draws the full amount from the provision (no new expense);
            // Direct recognizes the full amount as a new expense (no
            // provision drawn down).
            'provision_amount' => $method === 'Allowance' ? $outstanding['outstanding_balance'] : 0.0,
            'net_write_off_amount' => $method === 'Direct' ? $outstanding['outstanding_balance'] : 0.0,
            'reason' => $reason,
            'status' => 'Pending',
            'requested_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Accounting', 'Requested write-off #' . $writeOffId . ' for loan ' . $badDebt['loan_no']);

        // Creates a real maker-checker approval request only if the
        // loan_write_off_approval policy is currently active (Part 41's
        // staged-rollout "off switch") -- if it's ever turned off, approve()
        // below falls back to the pre-existing single-permission check, so
        // this never becomes a dead end for a write-off already in flight.
        ApprovalService::request('loan_write_off_approval', [
            'resource_id' => $writeOffId,
            'maker_user_id' => Auth::user()['id'] ?? null,
            'title' => 'Write-off ' . $writeOffNo . ' for loan ' . $badDebt['loan_no'] . ' (' . $method . ')',
            'amount' => $outstanding['outstanding_balance'],
            'reason' => $reason,
        ]);

        Session::flash('success', 'Write-off requested. It needs approval before it can be posted.');
        $this->redirect('/accounting/loan-write-offs/' . $writeOffId);
    }

    public function show(string $id): void
    {
        Auth::authorize('accounting.writeoffs');
        $writeOff = $this->writeOffs->find((int) $id);

        if (!$writeOff) {
            Session::flash('error', 'Write-off not found.');
            $this->redirect('/accounting/loan-write-offs');
            return;
        }

        $this->view('accounting/loan_write_offs/show', [
            'title' => 'Write-Off ' . $writeOff['write_off_no'],
            'writeOff' => $writeOff,
            'totalRecovered' => $this->writeOffs->totalRecoveredFor((int) $id),
            'recoveries' => $this->recoveries->forWriteOff((int) $id),
        ]);
    }

    /**
     * Routes through ApprovalService when a real approval request exists
     * for this write-off (the normal case -- see store(), which requests
     * one under the loan_write_off_approval policy). Falls back to the
     * pre-existing single-permission behavior only if that policy was
     * inactive at request time -- the Part 41 "disable immediately, no
     * code change" staged-rollout escape hatch. Either way, this method
     * still owns the write-off's own status transition; ApprovalService
     * never touches loan_write_offs directly.
     */
    public function approve(string $id): void
    {
        Auth::authorize('accounting.writeoffs');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $writeOff = $this->writeOffs->find($id);
        if (!$writeOff || $writeOff['status'] !== 'Pending') {
            Session::flash('error', 'Only pending write-offs can be approved.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $comments = trim((string) ($_POST['comments'] ?? ''));
        $approvalRequest = (new ApprovalRequest())->findPendingByResource('Accounting', 'loan_write_off', $id);

        if ($approvalRequest) {
            try {
                ApprovalService::approve((int) $approvalRequest['id'], $comments !== '' ? $comments : null);
            } catch (\RuntimeException $e) {
                Session::flash('error', $e->getMessage());
                $this->redirect('/accounting/loan-write-offs/' . $id);
                return;
            }
        }

        $this->writeOffs->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => Auth::user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Approve', 'Accounting', 'Approved write-off #' . $id);
        Session::flash('success', 'Write-off approved. It can now be posted.');
        $this->redirect('/accounting/loan-write-offs/' . $id);
    }

    /** No equivalent existed before this phase -- loan_write_offs.status has always had a Rejected value in its schema, but nothing wrote it. Only meaningful when a real approval request is open (see approve() above); a write-off submitted while the policy was inactive has no checker step to reject from, so this is a no-op refusal in that case rather than silently rejecting anyway. */
    public function reject(string $id): void
    {
        Auth::authorize('accounting.writeoffs');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $writeOff = $this->writeOffs->find($id);
        if (!$writeOff || $writeOff['status'] !== 'Pending') {
            Session::flash('error', 'Only pending write-offs can be rejected.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $comments = trim((string) ($_POST['comments'] ?? ''));
        $approvalRequest = (new ApprovalRequest())->findPendingByResource('Accounting', 'loan_write_off', $id);

        if (!$approvalRequest) {
            Session::flash('error', 'This write-off was requested before approval was required and has no open review step to reject.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        try {
            ApprovalService::reject((int) $approvalRequest['id'], $comments);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $this->writeOffs->updateRecord($id, ['status' => 'Rejected']);

        Audit::log('Reject', 'Accounting', 'Rejected write-off #' . $id . ': ' . $comments);
        Session::flash('success', 'Write-off rejected.');
        $this->redirect('/accounting/loan-write-offs/' . $id);
    }

    public function post(string $id): void
    {
        Auth::authorize('accounting.writeoffs');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $writeOff = $this->writeOffs->find($id);
        if (!$writeOff) {
            Session::flash('error', 'Write-off not found.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $key = $this->idempotencyKey();
        $loansReceivableId = $this->accounts->idByCode('1020');
        $outstanding = round((float) $writeOff['outstanding_balance'], 2);

        // Pure branch on the method selected at request time -- never both.
        // Allowance: the expense was already recognized when the provision
        // was raised, so only the provision moves now, for the full amount.
        // Direct: no provision exists for this write-off, so the full
        // amount is a new expense right now. Original Interest Income is
        // never reversed by a write-off, under either method -- it was
        // genuinely earned and stays on the books regardless of collection.
        $debitAccountId = $writeOff['write_off_method'] === 'Allowance'
            ? $this->accounts->idByCode('1050')
            : $this->accounts->idByCode('5010');

        $lines = [
            ['account_id' => $debitAccountId, 'debit' => $outstanding, 'credit' => 0],
            ['account_id' => $loansReceivableId, 'debit' => 0, 'credit' => $outstanding],
        ];

        $successMessage = 'Write-off posted and loan marked as written off.';

        try {
            $this->writeOffs->transaction(function () use ($id, $writeOff, $lines, $userId, $key, $outstanding, $successMessage) {
                Idempotency::begin($key, 'writeoff.post', $userId);

                $locked = $this->writeOffs->findForUpdate($id);
                if (!$locked || $locked['status'] !== 'Approved') {
                    throw new \RuntimeException('Only approved write-offs can be posted.');
                }

                $journalId = $this->journal->post(
                    'LOAN_WRITE_OFF',
                    'loan_write_offs',
                    $id,
                    $writeOff['write_off_no'],
                    'Write-off of loan ' . $writeOff['loan_no'] . ': ' . $writeOff['reason'],
                    $lines,
                    $userId
                );

                $this->writeOffs->updateRecord($id, [
                    'status' => 'Posted',
                    'posted_by' => $userId,
                    'posted_at' => date('Y-m-d H:i:s'),
                    'journal_id' => $journalId,
                ]);

                $this->loans->updateFields((int) $writeOff['loan_id'], ['loan_status' => 'Written Off']);
                $this->loans->logStatus((int) $writeOff['loan_id'], $writeOff['loan_status'] ?? null, 'Written Off', $userId, 'Written off via ' . $writeOff['write_off_no']);
                \App\Services\AgentCommissionService::onWriteOff((int) $writeOff['loan_id'], $userId);

                if (!empty($writeOff['bad_debt_id'])) {
                    $this->badDebts->updateRecord((int) $writeOff['bad_debt_id'], ['status' => 'Written Off']);
                }

                Audit::log('Post', 'Accounting', 'Posted write-off #' . $id . ' for loan ' . $writeOff['loan_no'] . ' (' . format_money($outstanding) . ')', [], $key);
                Idempotency::complete($key, 'writeoff.post', 'REDIRECT', [
                    'flash_type' => 'success',
                    'flash_message' => $successMessage,
                    'redirect' => '/accounting/loan-write-offs/' . $id,
                ]);
            });
        } catch (IdempotencyReplayException $e) {
            $this->replayIdempotent($e);
            return;
        } catch (IdempotencyBusyException $e) {
            $this->busyIdempotent($e, '/accounting/loan-write-offs/' . $id);
            return;
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        Session::flash('success', $successMessage);
        $this->redirect('/accounting/loan-write-offs/' . $id);
    }
}
