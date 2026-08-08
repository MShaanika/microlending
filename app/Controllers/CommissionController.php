<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AgentCommission;
use App\Models\AgentCommissionEntry;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\HrmEmployee;
use App\Models\LoanApplication;

class CommissionController extends Controller
{
    /** Dr'd for every payout -- see database/agent_commissions_module.sql for the account_code this expects. */
    private const COMMISSION_EXPENSE_ACCOUNT_CODE = '5310';

    private AgentCommission $commissions;
    private AgentCommissionEntry $entries;
    private Company $companies;
    private HrmEmployee $employees;
    private LoanApplication $applications;
    private BankAccount $bankAccounts;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->commissions = new AgentCommission();
        $this->entries = new AgentCommissionEntry();
        $this->companies = new Company();
        $this->employees = new HrmEmployee();
        $this->applications = new LoanApplication();
        $this->bankAccounts = new BankAccount();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    /**
     * Every application a marketing agent has ever submitted, whatever
     * stage it's at now -- broader than index() above, which only lists
     * loans that have already accrued a commission (i.e. already
     * disbursed). This is the "what are my agents sending in" overview;
     * index() is the "what do I owe them" ledger.
     */
    public function submissions(): void
    {
        Auth::authorize('commissions.manage');

        $filters = [
            'agent_employee_id' => !empty($_GET['agent_employee_id']) ? (int) $_GET['agent_employee_id'] : null,
            'status' => trim((string) ($_GET['status'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ];

        $this->view('commissions/submissions', [
            'title' => 'Agent Submissions',
            'rows' => $this->applications->agentSubmissions(array_filter($filters, fn ($v) => $v !== null && $v !== '')),
            'filters' => $filters,
            'agents' => $this->employees->commissionAgents(),
        ]);
    }

    public function index(): void
    {
        Auth::authorize('commissions.manage');

        $filters = [
            'agent_employee_id' => !empty($_GET['agent_employee_id']) ? (int) $_GET['agent_employee_id'] : null,
            'status' => trim((string) ($_GET['status'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ];

        $company = $this->companies->primary();

        $this->view('commissions/index', [
            'title' => 'Agent Commissions',
            'rows' => $this->commissions->paginated(array_filter($filters, fn ($v) => $v !== null && $v !== '')),
            'filters' => $filters,
            'agents' => $this->employees->commissionAgents(),
            'totalOutstanding' => $this->commissions->totalOutstanding(),
            'commissionRate' => $company['commission_rate_percent'] ?? 33.33,
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('commissions.manage');
        $commission = $this->commissions->find((int) $id);

        if (!$commission) {
            Session::flash('error', 'Commission record not found.');
            $this->redirect('/commissions');
            return;
        }

        $this->view('commissions/show', [
            'title' => 'Commission - ' . $commission['loan_no'],
            'commission' => $commission,
            'entries' => $this->entries->forCommission((int) $commission['id']),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
        ]);
    }

    public function markPaid(string $id): void
    {
        Auth::authorize('commissions.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/commissions/' . $id);
            return;
        }

        $commission = $this->commissions->find($id);
        if (!$commission) {
            Session::flash('error', 'Commission record not found.');
            $this->redirect('/commissions');
            return;
        }

        $outstanding = round((float) $commission['earned_amount'] - (float) $commission['paid_amount'], 2);
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;

        if ($amount <= 0 || $amount > $outstanding) {
            Session::flash('error', 'Enter a payout amount between 0 and ' . format_money($outstanding) . '.');
            $this->redirect('/commissions/' . $id);
            return;
        }
        if (!$bankAccount) {
            Session::flash('error', 'Select which bank account this is being paid from.');
            $this->redirect('/commissions/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $notes = trim($_POST['notes'] ?? '') ?: null;

        try {
            $expenseAccountId = $this->accounts->idByCode(self::COMMISSION_EXPENSE_ACCOUNT_CODE);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/commissions/' . $id);
            return;
        }

        $bankLabel = $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'];

        try {
            $journalId = $this->journal->post(
                'COMMISSION_PAYOUT',
                'agent_commissions',
                $id,
                $commission['loan_no'],
                'Agent commission paid: ' . $commission['agent_name'] . ' - ' . $commission['loan_no'],
                [
                    ['account_id' => $expenseAccountId, 'debit' => $amount, 'credit' => 0, 'description' => 'Commission payout - ' . $commission['loan_no']],
                    ['account_id' => (int) $bankAccount['account_id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Paid from ' . $bankLabel],
                ],
                $userId
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/commissions/' . $id);
            return;
        }

        $this->entries->create([
            'agent_commission_id' => $id,
            'payment_id' => null,
            'bank_account_id' => $bankAccountId,
            'journal_id' => $journalId,
            'entry_type' => 'Payout',
            'amount' => $amount,
            'notes' => $notes,
            'created_by' => $userId,
        ]);

        $this->commissions->updateRecord($id, [
            'paid_amount' => round((float) $commission['paid_amount'] + $amount, 2),
        ]);

        Audit::log('Payout', 'Commissions', 'Recorded ' . format_money($amount) . ' commission payout on #' . $id . ' (' . $commission['loan_no'] . ') from ' . $bankLabel . '.');
        Session::flash('success', format_money($amount) . ' payout recorded and journal entry posted.');
        $this->redirect('/commissions/' . $id);
    }

    public function updateSettings(): void
    {
        Auth::authorize('commissions.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/commissions');
            return;
        }

        $rate = (float) ($_POST['commission_rate_percent'] ?? 0);
        if ($rate <= 0 || $rate > 100) {
            Session::flash('error', 'Enter a commission rate between 0 and 100.');
            $this->redirect('/commissions');
            return;
        }

        $company = $this->companies->primary();
        if ($company) {
            $this->companies->updateRecord((int) $company['id'], ['commission_rate_percent' => $rate]);
        }

        Audit::log('Update', 'Commissions', 'Updated commission rate to ' . $rate . '%');
        Session::flash('success', 'Commission rate updated. This only affects loans disbursed from now on.');
        $this->redirect('/commissions');
    }
}
