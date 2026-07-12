<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\JournalEntry;

class AccountingAccountController extends Controller
{
    private AccountingAccount $accounts;
    private JournalEntry $journalEntries;

    public function __construct()
    {
        $this->accounts = new AccountingAccount();
        $this->journalEntries = new JournalEntry();
    }

    public function index(): void
    {
        Auth::authorize('accounting.chart');

        $accounts = $this->accounts->allAccounts();
        foreach ($accounts as &$account) {
            $account['balance'] = $this->journalEntries->accountBalance((int) $account['id'], $account['normal_balance']);
        }

        $this->view('accounting/accounts/index', [
            'title' => 'Chart of Accounts',
            'accounts' => $accounts,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('accounting.chart');
        $this->view('accounting/accounts/create', [
            'title' => 'Add Account',
            'parents' => $this->accounts->allAccounts(true),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('accounting.chart');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/accounts/create');
        }

        $errors = $this->validate($_POST);

        if (empty($errors) && $this->accounts->codeExists(trim($_POST['account_code']))) {
            $errors['account_code'] = 'This account code is already in use.';
        }

        if (!empty($errors)) {
            $this->view('accounting/accounts/create', [
                'title' => 'Add Account',
                'parents' => $this->accounts->allAccounts(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->accounts->create([
            'account_code' => trim($_POST['account_code']),
            'account_name' => trim($_POST['account_name']),
            'account_type' => $_POST['account_type'],
            'normal_balance' => $_POST['normal_balance'],
            'parent_account_id' => $_POST['parent_account_id'] !== '' ? (int) $_POST['parent_account_id'] : null,
            'is_control_account' => isset($_POST['is_control_account']) ? 1 : 0,
            'is_cash_bank_account' => isset($_POST['is_cash_bank_account']) ? 1 : 0,
            'is_active' => 1,
        ]);

        Audit::log('Create', 'Accounting', 'Created GL account ' . $_POST['account_code'] . ' - ' . $_POST['account_name']);
        Session::flash('success', 'Account created.');
        $this->redirect('/accounting/accounts');
    }

    public function edit(string $id): void
    {
        Auth::authorize('accounting.chart');
        $account = $this->accounts->find((int) $id);

        if (!$account) {
            Session::flash('error', 'Account not found.');
            $this->redirect('/accounting/accounts');
        }

        $this->view('accounting/accounts/edit', [
            'title' => 'Edit Account',
            'account' => $account,
            'parents' => array_filter($this->accounts->allAccounts(true), fn ($a) => (int) $a['id'] !== (int) $id),
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('accounting.chart');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/accounts/' . $id . '/edit');
        }

        $account = $this->accounts->find($id);
        if (!$account) {
            Session::flash('error', 'Account not found.');
            $this->redirect('/accounting/accounts');
        }

        $errors = $this->validate($_POST);

        if (empty($errors) && $this->accounts->codeExists(trim($_POST['account_code']), $id)) {
            $errors['account_code'] = 'This account code is already in use.';
        }

        if (!empty($errors)) {
            $this->view('accounting/accounts/edit', [
                'title' => 'Edit Account',
                'account' => array_merge($account, $_POST),
                'parents' => array_filter($this->accounts->allAccounts(true), fn ($a) => (int) $a['id'] !== $id),
                'errors' => $errors,
            ]);
            return;
        }

        $this->accounts->updateRecord($id, [
            'account_code' => trim($_POST['account_code']),
            'account_name' => trim($_POST['account_name']),
            'account_type' => $_POST['account_type'],
            'normal_balance' => $_POST['normal_balance'],
            'parent_account_id' => $_POST['parent_account_id'] !== '' ? (int) $_POST['parent_account_id'] : null,
            'is_control_account' => isset($_POST['is_control_account']) ? 1 : 0,
            'is_cash_bank_account' => isset($_POST['is_cash_bank_account']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ]);

        Audit::log('Update', 'Accounting', 'Updated GL account #' . $id);
        Session::flash('success', 'Account updated.');
        $this->redirect('/accounting/accounts');
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (trim((string) ($data['account_code'] ?? '')) === '') {
            $errors['account_code'] = 'Account code is required.';
        }
        if (trim((string) ($data['account_name'] ?? '')) === '') {
            $errors['account_name'] = 'Account name is required.';
        }
        if (empty($data['account_type'])) {
            $errors['account_type'] = 'Account type is required.';
        }
        if (empty($data['normal_balance'])) {
            $errors['normal_balance'] = 'Normal balance is required.';
        }
        return $errors;
    }
}
