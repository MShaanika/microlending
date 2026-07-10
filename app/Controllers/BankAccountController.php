<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\BankAccount;

class BankAccountController extends Controller
{
    private BankAccount $bankAccounts;
    private AccountingAccount $accounts;

    public function __construct()
    {
        $this->bankAccounts = new BankAccount();
        $this->accounts = new AccountingAccount();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $this->view('accounting/bank_accounts/index', [
            'title' => 'Bank Accounts',
            'bankAccounts' => $this->bankAccounts->allBankAccounts(),
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        $this->view('accounting/bank_accounts/create', [
            'title' => 'Add Bank Account',
            'glAccounts' => $this->accounts->cashBankAccounts(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-accounts/create');
        }

        $errors = $this->validate($_POST);

        if (empty($errors) && $this->bankAccounts->accountNumberExists(trim($_POST['account_number']))) {
            $errors['account_number'] = 'A bank account with this number already exists.';
        }

        if (!empty($errors)) {
            $this->view('accounting/bank_accounts/create', [
                'title' => 'Add Bank Account',
                'glAccounts' => $this->accounts->cashBankAccounts(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->bankAccounts->create([
            'account_name' => trim($_POST['account_name']),
            'bank_name' => trim($_POST['bank_name']),
            'account_number' => trim($_POST['account_number']),
            'branch' => trim($_POST['branch'] ?? '') ?: null,
            'branch_code' => trim($_POST['branch_code'] ?? '') ?: null,
            'swift_code' => trim($_POST['swift_code'] ?? '') ?: null,
            'account_id' => (int) $_POST['account_id'],
            'opening_balance' => (float) ($_POST['opening_balance'] ?? 0),
            'is_active' => 1,
        ]);

        Audit::log('Create', 'Accounting', 'Added bank account ' . $_POST['bank_name'] . ' - ' . $_POST['account_number']);
        Session::flash('success', 'Bank account added.');
        $this->redirect('/accounting/bank-accounts');
    }

    public function edit(string $id): void
    {
        Auth::requireLogin();
        $bankAccount = $this->bankAccounts->find((int) $id);

        if (!$bankAccount) {
            Session::flash('error', 'Bank account not found.');
            $this->redirect('/accounting/bank-accounts');
        }

        $this->view('accounting/bank_accounts/edit', [
            'title' => 'Edit Bank Account',
            'bankAccount' => $bankAccount,
            'glAccounts' => $this->accounts->cashBankAccounts(),
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/bank-accounts/' . $id . '/edit');
        }

        $bankAccount = $this->bankAccounts->find($id);
        if (!$bankAccount) {
            Session::flash('error', 'Bank account not found.');
            $this->redirect('/accounting/bank-accounts');
        }

        $errors = $this->validate($_POST);

        if (empty($errors) && $this->bankAccounts->accountNumberExists(trim($_POST['account_number']), $id)) {
            $errors['account_number'] = 'A bank account with this number already exists.';
        }

        if (!empty($errors)) {
            $this->view('accounting/bank_accounts/edit', [
                'title' => 'Edit Bank Account',
                'bankAccount' => array_merge($bankAccount, $_POST),
                'glAccounts' => $this->accounts->cashBankAccounts(),
                'errors' => $errors,
            ]);
            return;
        }

        $this->bankAccounts->updateRecord($id, [
            'account_name' => trim($_POST['account_name']),
            'bank_name' => trim($_POST['bank_name']),
            'account_number' => trim($_POST['account_number']),
            'branch' => trim($_POST['branch'] ?? '') ?: null,
            'branch_code' => trim($_POST['branch_code'] ?? '') ?: null,
            'swift_code' => trim($_POST['swift_code'] ?? '') ?: null,
            'account_id' => (int) $_POST['account_id'],
            'opening_balance' => (float) ($_POST['opening_balance'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ]);

        Audit::log('Update', 'Accounting', 'Updated bank account #' . $id);
        Session::flash('success', 'Bank account updated.');
        $this->redirect('/accounting/bank-accounts');
    }

    private function validate(array $data): array
    {
        $errors = [];
        foreach (['account_name', 'bank_name', 'account_number'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if (empty($data['account_id'])) {
            $errors['account_id'] = 'Select the GL account this bank account posts to.';
        }
        return $errors;
    }
}
