<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\BankAccount;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\StatutoryCharge;
use App\Services\LoanScheduleService;

class LoanController extends Controller
{
    private Loan $loans;
    private LoanProduct $products;
    private Borrower $borrowers;
    private StatutoryCharge $statutoryCharges;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;
    private BankAccount $bankAccounts;

    public function __construct()
    {
        $this->loans = new Loan();
        $this->products = new LoanProduct();
        $this->borrowers = new Borrower();
        $this->statutoryCharges = new StatutoryCharge();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
        $this->bankAccounts = new BankAccount();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('loans/index', [
            'title' => 'Loans',
            'loans' => $this->loans->paginated($search, $status),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();

        $preselectedBorrowerId = (int) ($_GET['borrower_id'] ?? 0);

        $this->view('loans/create', [
            'title' => 'New Loan',
            'borrowers' => $this->borrowers->paginated('', '', 500),
            'products' => $this->products->activeWithPlans(),
            'preselected_borrower_id' => $preselectedBorrowerId,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans/create');
        }

        $errors = [];
        foreach (['borrower_id', 'product_id', 'plan_id', 'principal_amount'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }

        $borrower = $this->borrowers->find((int) ($_POST['borrower_id'] ?? 0));
        $product = $this->products->find((int) ($_POST['product_id'] ?? 0));
        $plan = $product ? $this->products->findPlan((int) ($_POST['plan_id'] ?? 0)) : null;

        if (!$borrower) {
            $errors['borrower_id'] = 'Select a valid borrower.';
        }
        if (!$product) {
            $errors['product_id'] = 'Select a valid loan product.';
        }
        if (!$plan) {
            $errors['plan_id'] = 'Select a valid plan.';
        }

        $principal = (float) ($_POST['principal_amount'] ?? 0);
        if ($product && ($principal < (float) $product['min_amount'] || $principal > (float) $product['max_amount'])) {
            $errors['principal_amount'] = sprintf(
                'Principal must be between %s and %s for this product.',
                format_money($product['min_amount']),
                format_money($product['max_amount'])
            );
        }

        if (!empty($errors)) {
            $this->view('loans/create', [
                'title' => 'New Loan',
                'borrowers' => $this->borrowers->paginated('', '', 500),
                'products' => $this->products->activeWithPlans(),
                'preselected_borrower_id' => (int) ($_POST['borrower_id'] ?? 0),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $startDate = $_POST['start_date'] ?: date('Y-m-d');

        $namfisaRate = $this->statutoryCharges->currentNamfisaLevyRate();
        $dutyStampAmount = $this->statutoryCharges->currentDutyStampAmount();

        $schedule = LoanScheduleService::generate(
            $principal,
            (int) $plan['months'],
            (float) $plan['interest_rate'],
            (float) $plan['admin_fee'],
            $product['interest_method'],
            $startDate,
            $namfisaRate,
            $dutyStampAmount
        );

        $loanId = $this->loans->create([
            'branch_id' => (int) $borrower['branch_id'],
            'borrower_id' => (int) $borrower['id'],
            'product_id' => (int) $product['id'],
            'plan_id' => (int) $plan['id'],
            'loan_no' => generate_reference('LN'),
            'loan_type' => 'New Loan',
            'principal_amount' => $principal,
            'interest_amount' => $schedule['interest_amount'],
            'admin_fee' => $schedule['admin_fee'],
            'total_payable' => $schedule['total_payable'],
            'installment_amount' => $schedule['installment_amount'],
            'term_months' => (int) $plan['months'],
            'interest_rate' => (float) $plan['interest_rate'],
            'penalty_rate' => (float) $plan['penalty_rate'],
            'purpose' => trim($_POST['purpose'] ?? '') ?: null,
            'payment_day' => $_POST['payment_day'] !== '' ? (int) $_POST['payment_day'] : null,
            'loan_status' => 'Pending Approval',
            'approval_status' => 'Pending',
            'start_date' => $startDate,
            'maturity_date' => end($schedule['rows'])['due_date'] ?? null,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        $this->loans->insertScheduleRows($loanId, $schedule['rows']);
        $this->loans->logStatus($loanId, null, 'Pending Approval', Auth::user()['id'] ?? null, 'Loan created with amortization schedule.');

        if ($schedule['namfisa_levy'] > 0) {
            $this->statutoryCharges->recordNamfisaLevy([
                'loan_id' => $loanId,
                'borrower_id' => (int) $borrower['id'],
                'branch_id' => (int) $borrower['branch_id'],
                'levy_date' => $startDate,
                'levy_rate' => $namfisaRate,
                'basis_amount' => $principal,
                'levy_amount' => $schedule['namfisa_levy'],
                'status' => 'Calculated',
            ]);
        }

        if ($schedule['duty_stamp'] > 0) {
            $this->statutoryCharges->recordDutyStamp([
                'loan_id' => $loanId,
                'borrower_id' => (int) $borrower['id'],
                'branch_id' => (int) $borrower['branch_id'],
                'stamp_date' => $startDate,
                'basis_amount' => $principal,
                'stamp_amount' => $schedule['duty_stamp'],
                'status' => 'Calculated',
            ]);
        }

        Audit::log('Create', 'Loans', 'Created loan #' . $loanId . ' with ' . count($schedule['rows']) . '-period amortization schedule.');
        Session::flash('success', 'Loan created and amortization schedule generated.');
        $this->redirect('/loans/' . $loanId);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
        $loan = $this->loans->find((int) $id);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
        }

        $this->view('loans/show', [
            'title' => 'Loan ' . $loan['loan_no'],
            'loan' => $loan,
            'schedule' => $this->loans->schedule((int) $id),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
        ]);
    }

    public function approve(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans/' . $id);
        }

        $loan = $this->loans->find($id);
        if (!$loan || $loan['loan_status'] !== 'Pending Approval') {
            Session::flash('error', 'Only loans pending approval can be approved.');
            $this->redirect('/loans/' . $id);
        }

        $this->loans->updateFields($id, [
            'loan_status' => 'Approved',
            'approval_status' => 'Approved',
            'approved_by' => Auth::user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $this->loans->logStatus($id, 'Pending Approval', 'Approved', Auth::user()['id'] ?? null);

        Audit::log('Approve', 'Loans', 'Approved loan #' . $id);
        Session::flash('success', 'Loan approved.');
        $this->redirect('/loans/' . $id);
    }

    public function release(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans/' . $id);
        }

        $loan = $this->loans->find($id);
        if (!$loan || $loan['loan_status'] !== 'Approved') {
            Session::flash('error', 'Only approved loans can be released.');
            $this->redirect('/loans/' . $id);
        }

        $userId = Auth::user()['id'] ?? null;
        $bankAccountId = ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null;
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;

        // Post accounting first: if this fails (e.g. a closed accounting
        // period), nothing else below should happen either -- the loan must
        // not end up marked Active/disbursed with no journal behind it.
        try {
            $this->postDisbursementAccounting($id, $loan, $userId, $bankAccount);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/loans/' . $id);
        }

        $this->loans->updateFields($id, [
            'loan_status' => 'Active',
            'released_by' => $userId,
            'released_at' => date('Y-m-d H:i:s'),
        ]);
        $this->loans->logStatus($id, 'Approved', 'Active', $userId, 'Loan released / disbursed.');

        $this->loans->createDisbursement([
            'loan_id' => $id,
            'borrower_id' => (int) $loan['borrower_id'],
            'disbursement_no' => generate_reference('DSB'),
            'disbursement_date' => date('Y-m-d'),
            'disbursement_method' => $_POST['disbursement_method'] ?: 'Cash',
            'bank_account_id' => $bankAccount ? $bankAccount['id'] : null,
            'amount' => (float) $loan['principal_amount'],
            'reference_no' => trim($_POST['reference_no'] ?? '') ?: null,
            'status' => 'Disbursed',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
            'disbursed_by' => $userId,
            'disbursed_at' => date('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);

        Audit::log('Release', 'Loans', 'Released loan #' . $id);
        Session::flash('success', 'Loan released, disbursement recorded, and accounting entries posted.');
        $this->redirect('/loans/' . $id);
    }

    /**
     * Dr Loans Receivable (principal + NAMFISA levy + duty stamp -- what the
     * borrower must repay in total, excluding interest which is recognized
     * only as collected) / Cr Bank Account (principal, what actually leaves
     * the bank) / Cr NAMFISA Levy Payable / Cr Duty Stamp Payable (statutory
     * amounts owed to government, collected from the borrower via the loan).
     */
    private function postDisbursementAccounting(int $loanId, array $loan, ?int $userId, ?array $bankAccount = null): void
    {
        $principal = (float) $loan['principal_amount'];
        $levyTxn = $this->statutoryCharges->findNamfisaLevyByLoan($loanId);
        $stampTxn = $this->statutoryCharges->findDutyStampByLoan($loanId);
        $levy = $levyTxn ? (float) $levyTxn['levy_amount'] : 0.0;
        $stamp = $stampTxn ? (float) $stampTxn['stamp_amount'] : 0.0;

        $loanReceivable = $this->accounts->idByCode('1020');
        $bankGlAccount = $bankAccount ? (int) $bankAccount['account_id'] : $this->accounts->idByCode('1010');
        $bankLabel = $bankAccount ? $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'] : 'Bank Account';
        $levyPayable = $this->accounts->idByCode('2030');
        $stampPayable = $this->accounts->idByCode('2040');

        $lines = [
            [
                'account_id' => $loanReceivable,
                'debit' => round($principal + $levy + $stamp, 2),
                'credit' => 0,
                'description' => 'Loan receivable for ' . $loan['loan_no'],
            ],
            [
                'account_id' => $bankGlAccount,
                'debit' => 0,
                'credit' => $principal,
                'description' => 'Loan disbursed from ' . $bankLabel . ' for ' . $loan['loan_no'],
            ],
        ];

        if ($levy > 0) {
            $lines[] = [
                'account_id' => $levyPayable,
                'debit' => 0,
                'credit' => $levy,
                'description' => 'NAMFISA levy withheld for ' . $loan['loan_no'],
            ];
        }
        if ($stamp > 0) {
            $lines[] = [
                'account_id' => $stampPayable,
                'debit' => 0,
                'credit' => $stamp,
                'description' => 'Duty stamp withheld for ' . $loan['loan_no'],
            ];
        }

        $journalId = $this->journal->post(
            'LOAN_RELEASED',
            'loans',
            $loanId,
            $loan['loan_no'],
            'Loan disbursed: ' . $loan['loan_no'],
            $lines,
            $userId
        );

        if ($levyTxn) {
            $this->statutoryCharges->markNamfisaLevyPosted($loanId, $journalId);
        }
        if ($stampTxn) {
            $this->statutoryCharges->markDutyStampPosted($loanId, $journalId);
        }
    }
}
