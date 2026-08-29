<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Idempotency;
use App\Core\IdempotencyBusyException;
use App\Core\IdempotencyReplayException;
use App\Core\Security;
use App\Core\Session;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\Payment;

class PaymentController extends Controller
{
    private Payment $payments;
    private Loan $loans;
    private BankAccount $bankAccounts;
    private Branch $branches;

    public function __construct()
    {
        $this->payments = new Payment();
        $this->loans = new Loan();
        $this->bankAccounts = new BankAccount();
        $this->branches = new Branch();
    }

    /** Hard scope for create/store/confirm/reject -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        // 0 for a non-Super-Admin with no branch assigned -- never null,
        // since null means "unscoped" to the model layer and no real
        // branch has id 0, a misconfigured account sees nothing rather
        // than silently falling through to see every branch's data.
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow the list via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    /** Redirects away (404-style) if the record belongs to another branch and the viewer isn't Super Admin. */
    private function assertBranchAccess(?array $record, string $notFoundRedirect = '/payments'): void
    {
        if (!$record || Auth::isSuperAdmin()) {
            return;
        }
        if ((int) ($record['branch_id'] ?? 0) !== (int) Auth::branchId()) {
            Session::flash('error', 'Record not found.');
            $this->redirect($notFoundRedirect);
        }
    }

    public function index(): void
    {
        Auth::authorize('collections.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $branchId = $this->indexBranchId();

        $this->view('payments/index', [
            'title' => 'Payments',
            'payments' => $this->payments->paginated($search, 100, $branchId),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'search' => $search,
            'branches' => Auth::isSuperAdmin() ? $this->branches->all() : [],
            'selectedBranchId' => $branchId,
        ]);
    }

    public function create(string $loanId): void
    {
        Auth::authorize('collections.create');
        $loan = $this->loans->find((int) $loanId);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
        }
        $this->assertBranchAccess($loan, '/loans');

        $this->view('payments/create', [
            'title' => 'Record Payment',
            'loan' => $loan,
            'schedule' => $this->loans->schedule((int) $loanId),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('collections.create');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans');
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);
        $amount = (float) ($_POST['amount_received'] ?? 0);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
        }
        $this->assertBranchAccess($loan, '/loans');

        if ($amount <= 0) {
            Session::flash('error', 'Enter a payment amount greater than zero.');
            $this->redirect('/loans/' . $loanId . '/payments/create');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $key = $this->idempotencyKey();
        $successMessage = 'Payment recorded and allocated to the schedule.';

        // Payment::recordAndAllocate() already owns its own internal
        // transaction, so the idempotency check runs outside any
        // transaction here (autocommits immediately) rather than inside
        // one -- if recordAndAllocate() then throws, the PENDING row it
        // wrote won't be rolled back automatically, so fail() explicitly
        // removes it on the catch path to allow a genuine retry.
        try {
            Idempotency::begin($key, 'payment.store', $userId);
        } catch (IdempotencyReplayException $e) {
            $this->replayIdempotent($e);
            return;
        } catch (IdempotencyBusyException $e) {
            $this->busyIdempotent($e, '/loans/' . $loanId . '/payments/create');
            return;
        }

        try {
            $paymentId = $this->payments->recordAndAllocate($loan, $amount, [
                'payment_date' => $_POST['payment_date'] ?: date('Y-m-d'),
                'payment_source' => $_POST['payment_source'] ?: 'Cash',
                'bank_account_id' => ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null,
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'payer_name' => trim($_POST['payer_name'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'user_id' => $userId,
            ]);
        } catch (\RuntimeException $e) {
            Idempotency::fail($key, 'payment.store');
            Session::flash('error', $e->getMessage());
            $this->redirect('/loans/' . $loanId . '/payments/create');
            return;
        }

        Audit::log('Create', 'Collections', 'Recorded payment #' . $paymentId . ' of ' . format_money($amount) . ' for loan #' . $loanId, [], $key);
        Idempotency::complete($key, 'payment.store', 'REDIRECT', [
            'flash_type' => 'success',
            'flash_message' => $successMessage,
            'redirect' => '/loans/' . $loanId,
        ]);
        Session::flash('success', $successMessage);
        $this->redirect('/loans/' . $loanId);
    }

    /**
     * Confirm a payment reference a borrower logged through the self-service
     * portal: allocates it to the loan schedule and marks it Posted.
     */
    public function confirm(string $id): void
    {
        Auth::authorize('collections.post');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/payments');
        }

        $this->assertBranchAccess($this->payments->find($id));

        $bankAccountId = ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null;

        try {
            $ok = $this->payments->confirmPending($id, Auth::user()['id'] ?? null, $bankAccountId);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/payments');
        }

        if (!$ok) {
            Session::flash('error', 'This payment is no longer pending confirmation.');
            $this->redirect('/payments');
        }

        Audit::log('Confirm', 'Collections', 'Confirmed borrower-reported payment #' . $id);
        Session::flash('success', 'Payment confirmed and allocated to the schedule.');
        $this->redirect('/payments');
    }

    public function reject(string $id): void
    {
        Auth::authorize('collections.reverse');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/payments');
        }

        $this->assertBranchAccess($this->payments->find($id));

        $reason = trim($_POST['reason'] ?? '') ?: 'No reason given';
        $this->payments->rejectPending($id, Auth::user()['id'] ?? null, $reason);

        Audit::log('Reject', 'Collections', 'Rejected borrower-reported payment #' . $id . ': ' . $reason);
        Session::flash('success', 'Payment reference rejected.');
        $this->redirect('/payments');
    }
}
