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
use App\Models\InterestAccrual;
use App\Models\Loan;
use App\Models\LoanRecovery;
use App\Models\LoanWriteOff;
use App\Models\Penalty;
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

    public function __construct()
    {
        $this->writeOffs = new LoanWriteOff();
        $this->recoveries = new LoanRecovery();
        $this->badDebts = new BadDebt();
        $this->provisions = new BadDebtProvision();
        $this->loans = new Loan();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
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

        $outstanding = ArrearsService::loanOutstanding((int) $badDebt['loan_id'], date('Y-m-d'));
        $provisionAmount = $this->provisions->provisionForLoan((int) $badDebt['loan_id']);

        $this->view('accounting/loan_write_offs/create', [
            'title' => 'Write Off Loan ' . $badDebt['loan_no'],
            'badDebt' => $badDebt,
            'outstandingBalance' => $outstanding['outstanding_balance'],
            'provisionAmount' => $provisionAmount,
            'netWriteOffAmount' => round($outstanding['outstanding_balance'] - $provisionAmount, 2),
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

        if ($reason === '') {
            $this->view('accounting/loan_write_offs/create', [
                'title' => 'Write Off Loan ' . $badDebt['loan_no'],
                'badDebt' => $badDebt,
                'outstandingBalance' => (float) $_POST['outstanding_balance'],
                'provisionAmount' => (float) $_POST['provision_amount'],
                'netWriteOffAmount' => (float) $_POST['net_write_off_amount'],
                'errors' => ['reason' => 'A reason is required to request a write-off.'],
            ]);
            return;
        }

        $outstanding = ArrearsService::loanOutstanding((int) $badDebt['loan_id'], date('Y-m-d'));
        $provisionAmount = $this->provisions->provisionForLoan((int) $badDebt['loan_id']);

        $writeOffNo = generate_reference('WO');
        $writeOffId = $this->writeOffs->create([
            'loan_id' => $badDebt['loan_id'],
            'borrower_id' => $badDebt['borrower_id'],
            'branch_id' => $badDebt['branch_id'],
            'bad_debt_id' => $badDebtId,
            'write_off_no' => $writeOffNo,
            'write_off_date' => date('Y-m-d'),
            'loan_amount' => $outstanding['outstanding_balance'],
            'total_paid' => 0,
            'outstanding_balance' => $outstanding['outstanding_balance'],
            'provision_amount' => $provisionAmount,
            'net_write_off_amount' => round($outstanding['outstanding_balance'] - $provisionAmount, 2),
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
            'title' => 'Write-off ' . $writeOffNo . ' for loan ' . $badDebt['loan_no'],
            'amount' => round($outstanding['outstanding_balance'] - $provisionAmount, 2),
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
        $provisionAccountId = $this->accounts->idByCode('1050');
        $badDebtExpenseId = $this->accounts->idByCode('5010');

        $provisionPortion = round((float) $writeOff['provision_amount'], 2);
        $expensePortion = round((float) $writeOff['net_write_off_amount'], 2);
        $outstanding = round((float) $writeOff['outstanding_balance'], 2);

        $lines = [];
        if ($provisionPortion > 0) {
            $lines[] = ['account_id' => $provisionAccountId, 'debit' => $provisionPortion, 'credit' => 0];
        }
        if ($expensePortion > 0) {
            $lines[] = ['account_id' => $badDebtExpenseId, 'debit' => $expensePortion, 'credit' => 0];
        }
        $lines[] = ['account_id' => $loansReceivableId, 'debit' => 0, 'credit' => $outstanding];

        // Any penalty charged via the accrual run but never collected was
        // already recognized as Penalty Income the moment it was charged --
        // writing it off reverses that income (it will now never be
        // collected) and clears the receivable.
        $penaltyOutstanding = round((new Penalty())->outstandingForLoan((int) $writeOff['loan_id']), 2);
        if ($penaltyOutstanding > 0) {
            $lines[] = ['account_id' => $this->accounts->idByCode('4020'), 'debit' => $penaltyOutstanding, 'credit' => 0];
            $lines[] = ['account_id' => $this->accounts->idByCode('1040'), 'debit' => 0, 'credit' => $penaltyOutstanding];
        }

        // Same idea for interest already recognized via the accrual run
        // (InterestAccrualService) but never collected -- not-yet-due,
        // not-yet-accrued interest was never booked to 1030 in the first
        // place, so only the actually-accrued outstanding amount needs
        // reversing here.
        $interestOutstanding = round((new InterestAccrual())->outstandingForLoan((int) $writeOff['loan_id']), 2);
        if ($interestOutstanding > 0) {
            $lines[] = ['account_id' => $this->accounts->idByCode('4010'), 'debit' => $interestOutstanding, 'credit' => 0];
            $lines[] = ['account_id' => $this->accounts->idByCode('1030'), 'debit' => 0, 'credit' => $interestOutstanding];
        }

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
