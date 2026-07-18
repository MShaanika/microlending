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
use App\Models\LoanApplication;
use App\Models\LoanProduct;
use App\Models\LoanTopup;
use App\Models\Company;
use App\Models\StatutoryCharge;
use App\Services\LoanScheduleService;
use App\Services\LoanStatementExcelExporter;
use App\Services\LoanStatementService;
use App\Services\TopUpService;

class LoanController extends Controller
{
    private Loan $loans;
    private LoanProduct $products;
    private Borrower $borrowers;
    private StatutoryCharge $statutoryCharges;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;
    private BankAccount $bankAccounts;
    private Company $companies;
    private LoanTopup $topups;

    public function __construct()
    {
        $this->loans = new Loan();
        $this->products = new LoanProduct();
        $this->borrowers = new Borrower();
        $this->statutoryCharges = new StatutoryCharge();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
        $this->bankAccounts = new BankAccount();
        $this->companies = new Company();
        $this->topups = new LoanTopup();
    }

    public function index(): void
    {
        Auth::authorize('loans.view');
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
        Auth::authorize('loans.create');

        $preselectedBorrowerId = (int) ($_GET['borrower_id'] ?? 0);
        $applicationId = (int) ($_GET['application_id'] ?? 0);

        $old = [];
        if ($applicationId) {
            if (isset($_GET['amount']) && $_GET['amount'] !== '') {
                $old['principal_amount'] = $_GET['amount'];
            }
            if (isset($_GET['purpose']) && $_GET['purpose'] !== '') {
                $old['purpose'] = $_GET['purpose'];
            }
        }
        if (($_GET['loan_mode'] ?? '') === 'topup') {
            $old['loan_mode'] = 'topup';
            if (isset($_GET['existing_loan_id']) && $_GET['existing_loan_id'] !== '') {
                $old['existing_loan_id'] = $_GET['existing_loan_id'];
            }
        }

        $this->view('loans/create', [
            'title' => 'New Loan',
            'borrowers' => $this->borrowers->paginated('', '', 500),
            'products' => $this->products->activeWithPlans(),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'activeLoansByBorrower' => $this->buildActiveLoansByBorrower(),
            'preselected_borrower_id' => $preselectedBorrowerId,
            'application_id' => $applicationId,
            'old' => $old,
            'errors' => [],
        ]);
    }

    /**
     * borrower_id => [{id, loan_no, principal_amount, start_date}] for every
     * Active/Current loan -- embedded as JSON for the Top-up "existing loan"
     * picker on loan creation, cascaded client-side the same way product ->
     * plan already is.
     */
    private function buildActiveLoansByBorrower(): array
    {
        $map = [];
        foreach ($this->loans->activeLoansForTopup() as $l) {
            $map[(int) $l['borrower_id']][] = [
                'id' => (int) $l['id'],
                'loan_no' => $l['loan_no'],
                'principal_amount' => (float) $l['principal_amount'],
                'start_date' => $l['start_date'],
            ];
        }
        return $map;
    }

    public function store(): void
    {
        Auth::authorize('loans.create');

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

        $loanMode = ($_POST['loan_mode'] ?? 'new') === 'topup' ? 'topup' : 'new';
        $existingLoan = null;
        if ($loanMode === 'topup' && $borrower) {
            $existingLoan = $this->loans->find((int) ($_POST['existing_loan_id'] ?? 0));
            if (!$existingLoan
                || (int) $existingLoan['borrower_id'] !== (int) $borrower['id']
                || !in_array($existingLoan['loan_status'], ['Active', 'Current'], true)
            ) {
                $errors['existing_loan_id'] = 'Select a valid active loan for this borrower.';
                $existingLoan = null;
            } elseif ((new \App\Models\LoanReschedule())->hasImplementedReschedule((int) $existingLoan['id'])) {
                $errors['existing_loan_id'] = 'This loan has been rescheduled and can no longer be topped up -- use Reschedule instead.';
                $existingLoan = null;
            }
        }

        if (!empty($errors)) {
            $this->view('loans/create', [
                'title' => 'New Loan',
                'borrowers' => $this->borrowers->paginated('', '', 500),
                'products' => $this->products->activeWithPlans(),
                'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
                'activeLoansByBorrower' => $this->buildActiveLoansByBorrower(),
                'preselected_borrower_id' => (int) ($_POST['borrower_id'] ?? 0),
                'application_id' => (int) ($_POST['application_id'] ?? 0),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $startDate = $_POST['start_date'] ?: date('Y-m-d');
        $userId = Auth::user()['id'] ?? null;

        // Top-up mode: a loan disbursed this same calendar month with zero
        // payments posted is consolidated into that same loan instead of
        // spawning a second one -- see TopUpService for the full rule.
        if ($loanMode === 'topup' && $existingLoan) {
            $requestDate = date('Y-m-d');

            if (TopUpService::shouldConsolidate($existingLoan, $requestDate)) {
                $bankAccountId = ($_POST['bank_account_id'] ?? '') !== '' ? (int) $_POST['bank_account_id'] : null;
                $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;

                try {
                    $loanId = TopUpService::consolidate($existingLoan, $product, $plan, $principal, $requestDate, $bankAccount, $userId);
                } catch (\RuntimeException $e) {
                    Session::flash('error', $e->getMessage());
                    $this->redirect('/loans/create');
                    return;
                }

                Audit::log('Top-up', 'Loans', 'Topped up loan #' . $loanId . ' by ' . format_money($principal) . ' (consolidated).');
                Session::flash('success', 'Loan topped up by ' . format_money($principal) . ' and consolidated into ' . $existingLoan['loan_no'] . '. New principal: ' . format_money((float) $existingLoan['principal_amount'] + $principal) . '.');
                $this->redirect('/loans/' . $loanId);
                return;
            }

            // Different month, or the loan already has a payment posted:
            // falls through to a normal separate loan, tagged as a Top-up
            // and linked back to the original for reference.
            $reason = TopUpService::isSameMonth($existingLoan['start_date'], $requestDate)
                ? ('loan ' . $existingLoan['loan_no'] . ' already has a payment recorded this month')
                : ('loan ' . $existingLoan['loan_no'] . ' was disbursed in a different month');
        }

        $namfisaRate = $this->statutoryCharges->currentNamfisaLevyRate();
        $dutyStampAmount = $this->statutoryCharges->currentDutyStampAmount();
        $paymentDay = $_POST['payment_day'] !== '' ? (int) $_POST['payment_day'] : null;

        $schedule = LoanScheduleService::generate(
            $principal,
            (int) $plan['months'],
            (float) $plan['interest_rate'],
            (float) $plan['admin_fee'],
            $product['interest_method'],
            $startDate,
            $namfisaRate,
            $dutyStampAmount,
            $paymentDay
        );

        $applicationId = (int) ($_POST['application_id'] ?? 0);

        $loanId = $this->loans->create([
            'branch_id' => (int) $borrower['branch_id'],
            'borrower_id' => (int) $borrower['id'],
            'application_id' => $applicationId ?: null,
            'topup_of_loan_id' => $existingLoan ? (int) $existingLoan['id'] : null,
            'product_id' => (int) $product['id'],
            'plan_id' => (int) $plan['id'],
            'loan_no' => generate_reference('LN'),
            'loan_type' => $existingLoan ? 'Top-up' : 'New Loan',
            'principal_amount' => $principal,
            'interest_amount' => $schedule['interest_amount'],
            'admin_fee' => $schedule['admin_fee'],
            'total_payable' => $schedule['total_payable'],
            'installment_amount' => $schedule['installment_amount'],
            'term_months' => (int) $plan['months'],
            'interest_rate' => (float) $plan['interest_rate'],
            'penalty_rate' => (float) $plan['penalty_rate'],
            'purpose' => trim($_POST['purpose'] ?? '') ?: null,
            'payment_day' => $paymentDay,
            'quarter_month' => $_POST['quarter_month'] ?: null,
            'loan_status' => 'Pending Approval',
            'approval_status' => 'Pending',
            'start_date' => $startDate,
            'maturity_date' => end($schedule['rows'])['due_date'] ?? null,
            'created_by' => $userId,
        ]);

        $this->loans->insertScheduleRows($loanId, $schedule['rows']);
        $this->loans->logStatus(
            $loanId,
            null,
            'Pending Approval',
            $userId,
            $existingLoan ? ('Created as a separate top-up loan because ' . $reason . '.') : 'Loan created with amortization schedule.'
        );

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

        if ($applicationId) {
            $applications = new LoanApplication();
            $application = $applications->find($applicationId);
            if ($application) {
                $applications->updateRecord($applicationId, ['status' => 'Converted to Loan']);
                $applications->addStatusHistory($applicationId, $application['status'], 'Converted to Loan', $userId, 'Converted to loan #' . $loanId . '.');
            }
        }

        if ($existingLoan) {
            Audit::log('Create', 'Loans', 'Created top-up loan #' . $loanId . ' linked to loan #' . $existingLoan['id'] . '.');
            Session::flash('success', 'Loan created as a separate top-up because ' . $reason . '.');
            $this->redirect('/loans/' . $loanId . '/topup-created');
            return;
        }

        Audit::log('Create', 'Loans', 'Created loan #' . $loanId . ' with ' . count($schedule['rows']) . '-period amortization schedule.');
        Session::flash('success', 'Loan created and amortization schedule generated.');
        $this->redirect('/loans/' . $loanId);
    }

    public function topupCreated(string $id): void
    {
        Auth::authorize('loans.create');
        $loan = $this->loans->find((int) $id);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }

        $originalLoan = $loan['topup_of_loan_id'] ? $this->loans->find((int) $loan['topup_of_loan_id']) : null;

        $this->view('loans/topup_success', [
            'title' => 'Top-up Loan Created',
            'loan' => $loan,
            'originalLoan' => $originalLoan,
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('loans.view');
        $loan = $this->loans->find((int) $id);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
        }

        $originalLoan = $loan['topup_of_loan_id'] ? $this->loans->find((int) $loan['topup_of_loan_id']) : null;
        $latestTopup = $this->topups->latestActiveForLoan((int) $id);
        $levyTxn = $this->statutoryCharges->findNamfisaLevyByLoan((int) $id);
        $stampTxn = $this->statutoryCharges->findDutyStampByLoan((int) $id);

        $this->view('loans/show', [
            'title' => 'Loan ' . $loan['loan_no'],
            'loan' => $loan,
            'schedule' => $this->loans->schedule((int) $id),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'originalLoan' => $originalLoan,
            'topups' => $this->loans->topupsOf((int) $id),
            'latestTopup' => $latestTopup,
            'latestTopupReversible' => $latestTopup ? !TopUpService::hasAnyPayment((int) $id) : false,
            'hasReschedule' => (new \App\Models\LoanReschedule())->hasImplementedReschedule((int) $id),
            'namfisaLevy' => $levyTxn ? (float) $levyTxn['levy_amount'] : 0.0,
            'dutyStamp' => $stampTxn ? (float) $stampTxn['stamp_amount'] : 0.0,
        ]);
    }

    public function reverseTopup(string $topupId): void
    {
        Auth::authorize('loans.edit');
        $topupId = (int) $topupId;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans');
            return;
        }

        $topup = $this->topups->find($topupId);
        if (!$topup) {
            Session::flash('error', 'Top-up record not found.');
            $this->redirect('/loans');
            return;
        }

        $loanId = (int) $topup['loan_id'];

        try {
            TopUpService::reverseConsolidation($topup, Auth::user()['id'] ?? null);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/loans/' . $loanId);
            return;
        }

        Audit::log('Reverse', 'Loans', 'Reversed top-up #' . $topupId . ' on loan #' . $loanId . '.');
        Session::flash('success', 'Top-up reversed. The loan has been restored to its terms from before that top-up.');
        $this->redirect('/loans/' . $loanId);
    }

    /**
     * Printable loan statement of account (schedule + full transaction
     * ledger). Staff-side equivalent of the borrower portal's invoice --
     * no PDF library is installed, so this renders clean, print-ready HTML;
     * "Print" in the browser produces a perfectly good PDF via Save as PDF.
     */
    public function statement(string $id): void
    {
        Auth::authorize('loans.view');
        $loan = $this->loans->find((int) $id);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }

        $this->view('loans/statement', [
            'title' => 'Statement - ' . $loan['loan_no'],
            'loan' => $loan,
            'schedule' => $this->loans->schedule((int) $id),
            'borrower' => $this->borrowers->find((int) $loan['borrower_id']),
            'company' => $this->companies->primary(),
            'ledger' => LoanStatementService::ledger((int) $id),
        ]);
    }

    public function statementExcel(string $id): void
    {
        Auth::authorize('loans.view');
        $loan = $this->loans->find((int) $id);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }

        $borrower = $this->borrowers->find((int) $loan['borrower_id']);
        $schedule = $this->loans->schedule((int) $id);
        $ledger = LoanStatementService::ledger((int) $id);
        $company = $this->companies->primary();

        $spreadsheet = LoanStatementExcelExporter::build($loan, $borrower, $schedule, $ledger, $company);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Statement_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $loan['loan_no']) . '.xlsx"');
        header('Cache-Control: max-age=0');

        LoanStatementExcelExporter::save($spreadsheet, 'php://output');
        exit;
    }

    public function approve(string $id): void
    {
        Auth::authorize('loans.approve');
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
        Auth::authorize('loans.release');
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
