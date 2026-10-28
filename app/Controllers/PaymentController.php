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
use App\Models\CollexiaSetting;
use App\Models\Payment;
use App\Services\MandateCollectionAutoStop;
use App\Services\MandateCollectionCheck;

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

        // An original loan with released top-ups takes ONE payment, split across the loan and its top-ups (whatever is due soonest first).
        $bundle = \App\Services\CombinedLoanService::forLoan($loan, $this->loans);

        $this->view('payments/create', [
            'title' => 'Record Payment',
            'loan' => $loan,
            'combinedTranches' => $bundle ? $bundle['tranches'] : [],
            'schedule' => $bundle ? $bundle['schedule'] : $this->loans->schedule((int) $loanId),
            'collectionCheck' => (new \App\Services\MandateCollectionCheck())->forLoan((int) $loanId),
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
            $meta = [
                'payment_date' => $_POST['payment_date'] ?: date('Y-m-d'),
                'payment_source' => $_POST['payment_source'] ?: 'Cash',
                'bank_account_id' => ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null,
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'payer_name' => trim($_POST['payer_name'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'user_id' => $userId,
            ];
            $tranches = $this->loans->tranchesOf($loanId);
            if ($tranches !== []) {
                $split = $this->payments->recordCombined($loan, $tranches, $amount, $meta);
                $paymentId = (int) reset($split);
                $splitLoanIds = array_keys($split);
                $successMessage = 'Payment recorded and split across ' . count($split) . ' loan' . (count($split) === 1 ? '' : 's') . ' (the original and its top-ups), whatever is due soonest first.';
            } else {
                $paymentId = $this->payments->recordAndAllocate($loan, $amount, $meta);
                $splitLoanIds = [$loanId];
            }
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
        // The payment is saved. If it cleared an instalment that a live Collexia mandate is still set to collect, that collection is
        // cancelled automatically and the result is told here.
        $stopMessages = [];
        foreach (array_values(array_unique(array_merge([$loanId], $splitLoanIds))) as $paidLoanId) {
            $stopMessages[] = $this->autoStopCollections((int) $paidLoanId);
        }
        Session::flash('success', trim($successMessage . ' ' . implode(' ', array_filter($stopMessages))));
        $this->redirect('/loans/' . $loanId);
    }

    /**
     * Cancels, automatically, the Collexia collections a just-recorded payment made unnecessary, and returns what to add to the success
     * message (what was cancelled -- or, if that is switched off, a pointer to the red notice). Anything it could not stop is flashed as a
     * warning. Never throws: the payment is already saved and must not be undone by a problem here.
     */
    private function autoStopCollections(int $loanId): string
    {
        try {
            $configured = (new CollexiaSetting())->frontEndUsername();
            $user = $configured !== '' ? $configured : (string) (Auth::user()['username'] ?? '');
            $auto = (new MandateCollectionAutoStop())->afterPayment($loanId, Auth::user()['id'] ?? null, $user);

            if ($auto['problems']) {
                Session::flash('error', implode(' ', $auto['problems']));
            }
            if ($auto['messages']) {
                return implode(' ', $auto['messages']);
            }
            if (!$auto['attempted'] && !$auto['problems']) {
                $check = (new MandateCollectionCheck())->forLoan($loanId);
                if (!empty($check['paidButScheduled']) || !empty($check['surplus'])) {
                    return 'HEADS UP: this loan has a live debit order that is still set to collect what you just paid -- see the red notice below to stop it.';
                }
            }
        } catch (\Throwable $e) {
            // Deliberately silent: the red notice on the loan page still shows anything left to stop.
        }
        return '';
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
        $confirmedLoanId = (int) ($this->payments->find($id)['loan_id'] ?? 0);
        Session::flash('success', trim('Payment confirmed and allocated to the schedule. ' . ($confirmedLoanId ? $this->autoStopCollections($confirmedLoanId) : '')));
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
