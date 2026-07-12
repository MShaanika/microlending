<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\Payment;

class PaymentController extends Controller
{
    private Payment $payments;
    private Loan $loans;
    private BankAccount $bankAccounts;

    public function __construct()
    {
        $this->payments = new Payment();
        $this->loans = new Loan();
        $this->bankAccounts = new BankAccount();
    }

    public function index(): void
    {
        Auth::authorize('collections.view');
        $search = trim((string) ($_GET['q'] ?? ''));

        $this->view('payments/index', [
            'title' => 'Payments',
            'payments' => $this->payments->paginated($search),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'search' => $search,
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

        if ($amount <= 0) {
            Session::flash('error', 'Enter a payment amount greater than zero.');
            $this->redirect('/loans/' . $loanId . '/payments/create');
        }

        try {
            $paymentId = $this->payments->recordAndAllocate($loan, $amount, [
                'payment_date' => $_POST['payment_date'] ?: date('Y-m-d'),
                'payment_source' => $_POST['payment_source'] ?: 'Cash',
                'bank_account_id' => ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null,
                'reference_no' => trim($_POST['reference_no'] ?? ''),
                'payer_name' => trim($_POST['payer_name'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'user_id' => Auth::user()['id'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/loans/' . $loanId . '/payments/create');
        }

        Audit::log('Create', 'Collections', 'Recorded payment #' . $paymentId . ' of ' . format_money($amount) . ' for loan #' . $loanId);
        Session::flash('success', 'Payment recorded and allocated to the schedule.');
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

        $reason = trim($_POST['reason'] ?? '') ?: 'No reason given';
        $this->payments->rejectPending($id, Auth::user()['id'] ?? null, $reason);

        Audit::log('Reject', 'Collections', 'Rejected borrower-reported payment #' . $id . ': ' . $reason);
        Session::flash('success', 'Payment reference rejected.');
        $this->redirect('/payments');
    }
}
