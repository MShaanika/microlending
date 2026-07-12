<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\ExpenseCategory;

class ExpenseCategoryController extends Controller
{
    private ExpenseCategory $categories;
    private AccountingAccount $accounts;

    public function __construct()
    {
        $this->categories = new ExpenseCategory();
        $this->accounts = new AccountingAccount();
    }

    public function index(): void
    {
        Auth::authorize('expenses.view');
        $this->view('expense_categories/index', [
            'title' => 'Expense Categories',
            'categories' => $this->categories->allCategories(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('expenses.create');
        $this->view('expense_categories/create', [
            'title' => 'Add Expense Category',
            'expenseAccounts' => $this->expenseAccounts(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('expenses.create');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/expense-categories/create');
            return;
        }

        $name = trim($_POST['category_name'] ?? '');
        $errors = [];

        if ($name === '') {
            $errors['category_name'] = 'Category name is required.';
        } elseif ($this->categories->nameExists($name)) {
            $errors['category_name'] = 'A category with this name already exists.';
        }
        if (empty($_POST['account_id'])) {
            $errors['account_id'] = 'Select the GL expense account this category posts to.';
        }

        if (!empty($errors)) {
            $this->view('expense_categories/create', [
                'title' => 'Add Expense Category',
                'expenseAccounts' => $this->expenseAccounts(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->categories->create([
            'category_name' => $name,
            'account_id' => (int) $_POST['account_id'],
            'description' => trim($_POST['description'] ?? '') ?: null,
            'is_active' => 1,
        ]);

        Audit::log('Create', 'Expenses', 'Created expense category #' . $id . ' - ' . $name);
        Session::flash('success', 'Expense category created.');
        $this->redirect('/expense-categories');
    }

    private function expenseAccounts(): array
    {
        return array_values(array_filter(
            $this->accounts->allAccounts(true),
            fn ($a) => $a['account_type'] === 'Expense'
        ));
    }
}
