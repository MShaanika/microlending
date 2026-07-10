<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BadDebt;
use App\Models\BadDebtProvision;
use App\Models\Loan;
use App\Models\LoanWriteOff;
use App\Models\Penalty;
use App\Services\ArrearsService;

class LoanWriteOffController extends Controller
{
    private LoanWriteOff $writeOffs;
    private BadDebt $badDebts;
    private BadDebtProvision $provisions;
    private Loan $loans;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->writeOffs = new LoanWriteOff();
        $this->badDebts = new BadDebt();
        $this->provisions = new BadDebtProvision();
        $this->loans = new Loan();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('accounting/loan_write_offs/index', [
            'title' => 'Loan Write-Offs',
            'writeOffs' => $this->writeOffs->paginated($status),
            'status' => $status,
        ]);
    }

    public function create(string $badDebtId): void
    {
        Auth::requireLogin();
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
        Auth::requireLogin();

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

        $writeOffId = $this->writeOffs->create([
            'loan_id' => $badDebt['loan_id'],
            'borrower_id' => $badDebt['borrower_id'],
            'branch_id' => $badDebt['branch_id'],
            'bad_debt_id' => $badDebtId,
            'write_off_no' => generate_reference('WO'),
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
        Session::flash('success', 'Write-off requested. It needs approval before it can be posted.');
        $this->redirect('/accounting/loan-write-offs/' . $writeOffId);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
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
        ]);
    }

    public function approve(string $id): void
    {
        Auth::requireLogin();
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

        $this->writeOffs->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => Auth::user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Approve', 'Accounting', 'Approved write-off #' . $id);
        Session::flash('success', 'Write-off approved. It can now be posted.');
        $this->redirect('/accounting/loan-write-offs/' . $id);
    }

    public function post(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $writeOff = $this->writeOffs->find($id);
        if (!$writeOff || $writeOff['status'] !== 'Approved') {
            Session::flash('error', 'Only approved write-offs can be posted.');
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
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

        // Any penalty charged via the accrual run but never collected is
        // still sitting as a Penalty Receivable against Deferred Penalty
        // Income -- neither side was ever recognized as P&L income, so
        // writing it off just clears both balance-sheet legs, with no
        // additional expense.
        $penaltyOutstanding = round((new Penalty())->outstandingForLoan((int) $writeOff['loan_id']), 2);
        if ($penaltyOutstanding > 0) {
            $lines[] = ['account_id' => $this->accounts->idByCode('2050'), 'debit' => $penaltyOutstanding, 'credit' => 0];
            $lines[] = ['account_id' => $this->accounts->idByCode('1040'), 'debit' => 0, 'credit' => $penaltyOutstanding];
        }

        try {
            $journalId = $this->journal->post(
                'LOAN_WRITE_OFF',
                'loan_write_offs',
                $id,
                $writeOff['write_off_no'],
                'Write-off of loan ' . $writeOff['loan_no'] . ': ' . $writeOff['reason'],
                $lines,
                $userId
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/accounting/loan-write-offs/' . $id);
            return;
        }

        $this->writeOffs->updateRecord($id, [
            'status' => 'Posted',
            'posted_by' => $userId,
            'posted_at' => date('Y-m-d H:i:s'),
            'journal_id' => $journalId,
        ]);

        $this->loans->updateFields((int) $writeOff['loan_id'], ['loan_status' => 'Written Off']);
        $this->loans->logStatus((int) $writeOff['loan_id'], $writeOff['loan_status'] ?? null, 'Written Off', $userId, 'Written off via ' . $writeOff['write_off_no']);

        if (!empty($writeOff['bad_debt_id'])) {
            $this->badDebts->updateRecord((int) $writeOff['bad_debt_id'], ['status' => 'Written Off']);
        }

        Audit::log('Post', 'Accounting', 'Posted write-off #' . $id . ' for loan ' . $writeOff['loan_no'] . ' (' . format_money($outstanding) . ')');
        Session::flash('success', 'Write-off posted and loan marked as written off.');
        $this->redirect('/accounting/loan-write-offs/' . $id);
    }
}
