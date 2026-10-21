<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\LoanProduct;

class LoanProductController extends Controller
{
    private LoanProduct $products;

    public function __construct()
    {
        $this->products = new LoanProduct();
    }

    public function index(): void
    {
        Auth::authorize('loans.view');
        $this->view('loan_products/index', [
            'title' => 'Loan Products',
            'products' => $this->products->allWithPlans(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('loans.edit');
        $this->view('loan_products/create', [
            'title' => 'Add Loan Product',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('loans.edit');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loan-products/create');
        }

        $errors = [];
        foreach (['product_name', 'min_amount', 'max_amount', 'min_term_months', 'max_term_months', 'interest_rate'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }

        $code = trim($_POST['product_code'] ?? '') ?: strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $_POST['product_name'] ?? 'PRD'), 0, 8));
        if ($this->products->codeExists($code)) {
            $code .= '-' . random_int(10, 99);
        }

        if (!empty($errors)) {
            $this->view('loan_products/create', ['title' => 'Add Loan Product', 'old' => $_POST, 'errors' => $errors]);
            return;
        }

        $productId = $this->products->create([
            'product_code' => $code,
            'product_name' => trim($_POST['product_name']),
            'description' => trim($_POST['description'] ?? '') ?: null,
            'min_amount' => (float) $_POST['min_amount'],
            'max_amount' => (float) $_POST['max_amount'],
            'min_term_months' => (int) $_POST['min_term_months'],
            'max_term_months' => (int) $_POST['max_term_months'],
            'interest_method' => $_POST['interest_method'] ?: 'Flat',
            'interest_rate' => (float) $_POST['interest_rate'],
            'penalty_rate' => (float) ($_POST['penalty_rate'] ?? 0),
            'service_fee' => (float) ($_POST['service_fee'] ?? 0),
            'is_active' => 1,
        ]);

        // Every product needs at least one plan for loans.plan_id (NOT NULL).
        $this->products->addPlan([
            'product_id' => $productId,
            'plan_name' => 'Standard ' . (int) $_POST['max_term_months'] . ' Month Plan',
            'months' => (int) $_POST['max_term_months'],
            'interest_rate' => (float) $_POST['interest_rate'],
            'penalty_rate' => (float) ($_POST['penalty_rate'] ?? 0),
            'admin_fee' => (float) ($_POST['service_fee'] ?? 0),
            'is_active' => 1,
        ]);

        Audit::log('Create', 'Loan Products', 'Created loan product ' . $code);
        Session::flash('success', 'Loan product created with a default plan.');
        $this->redirect('/loan-products');
    }

    /**
     * Edit a product's name, description and LIMITS (amount and term). Rates, interest method and fees are deliberately not editable here:
     * they live on each plan, and every loan stores its own copy, so changing a limit only governs loans created from now on -- existing
     * loans, schedules and balances are never touched.
     */
    public function edit(string $id): void
    {
        Auth::authorize('loans.edit');
        $product = $this->products->find((int) $id);
        if (!$product) {
            Session::flash('error', 'Loan product not found.');
            $this->redirect('/loan-products');
        }
        $this->view('loan_products/edit', ['title' => 'Edit Loan Product', 'product' => $product, 'old' => [], 'errors' => []]);
    }

    public function update(string $id): void
    {
        Auth::authorize('loans.edit');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loan-products/' . $id . '/edit');
        }
        $product = $this->products->find($id);
        if (!$product) {
            Session::flash('error', 'Loan product not found.');
            $this->redirect('/loan-products');
        }

        $name = trim((string) ($_POST['product_name'] ?? ''));
        $minAmount = (string) ($_POST['min_amount'] ?? '');
        $maxAmount = (string) ($_POST['max_amount'] ?? '');
        $minTerm = (string) ($_POST['min_term_months'] ?? '');
        $maxTerm = (string) ($_POST['max_term_months'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors['product_name'] = 'This field is required.';
        }
        if (!is_numeric($minAmount) || (float) $minAmount < 0) {
            $errors['min_amount'] = 'Enter the smallest loan amount (0 or more).';
        }
        if (!is_numeric($maxAmount) || (float) $maxAmount <= 0) {
            $errors['max_amount'] = 'Enter the largest loan amount (more than 0).';
        } elseif (!isset($errors['min_amount']) && (float) $maxAmount < (float) $minAmount) {
            $errors['max_amount'] = 'The maximum cannot be less than the minimum.';
        }
        if (!ctype_digit($minTerm) || (int) $minTerm < 1) {
            $errors['min_term_months'] = 'Enter a whole number of months (1 or more).';
        }
        if (!ctype_digit($maxTerm) || (int) $maxTerm < 1) {
            $errors['max_term_months'] = 'Enter a whole number of months (1 or more).';
        } elseif (!isset($errors['min_term_months']) && (int) $maxTerm < (int) $minTerm) {
            $errors['max_term_months'] = 'The maximum term cannot be shorter than the minimum.';
        }

        if ($errors !== []) {
            $this->view('loan_products/edit', ['title' => 'Edit Loan Product', 'product' => $product, 'old' => $_POST, 'errors' => $errors]);
            return;
        }

        $new = [
            'product_name' => $name,
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'min_amount' => round((float) $minAmount, 2),
            'max_amount' => round((float) $maxAmount, 2),
            'min_term_months' => (int) $minTerm,
            'max_term_months' => (int) $maxTerm,
        ];

        $changes = [];
        foreach ($new as $field => $value) {
            $before = $product[$field];
            $same = is_numeric($value) && is_numeric($before) ? abs((float) $value - (float) $before) < 0.005 : (string) $value === (string) $before;
            if (!$same) {
                $changes[] = $field . ': ' . ($before === null || $before === '' ? '(blank)' : $before) . ' -> ' . ($value ?? '(blank)');
            }
        }
        if ($changes === []) {
            Session::flash('success', 'Nothing was changed.');
            $this->redirect('/loan-products');
        }

        $this->products->updateRecord($id, $new);
        Audit::log('Update', 'Loan Products', 'Edited loan product ' . $product['product_code'] . ' (#' . $id . '): ' . implode('; ', $changes));
        Session::flash('success', 'Loan product updated. The new limits apply to loans created from now on; existing loans are unchanged.');
        $this->redirect('/loan-products');
    }

    public function addPlan(string $productId): void
    {
        Auth::authorize('loans.edit');
        $productId = (int) $productId;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loan-products');
        }

        $product = $this->products->find($productId);
        if (!$product) {
            Session::flash('error', 'Loan product not found.');
            $this->redirect('/loan-products');
        }

        $this->products->addPlan([
            'product_id' => $productId,
            'plan_name' => trim($_POST['plan_name'] ?: ((int) $_POST['months'] . ' Month Plan')),
            'months' => (int) $_POST['months'],
            'interest_rate' => (float) $_POST['interest_rate'],
            'penalty_rate' => (float) ($_POST['penalty_rate'] ?? $product['penalty_rate']),
            'admin_fee' => (float) ($_POST['admin_fee'] ?? 0),
            'is_active' => 1,
        ]);

        Audit::log('Create', 'Loan Products', 'Added plan to product #' . $productId);
        Session::flash('success', 'Plan added.');
        $this->redirect('/loan-products');
    }

    /** Deactivating a product hides it (and, independently, its plans) from the "new loan" picker -- see LoanProduct::activeWithPlans() -- without touching any loan already issued under it. */
    public function toggleActive(string $id): void
    {
        Auth::authorize('loans.edit');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loan-products');
            return;
        }

        $product = $this->products->find($id);
        if (!$product) {
            Session::flash('error', 'Loan product not found.');
            $this->redirect('/loan-products');
            return;
        }

        $newState = $product['is_active'] ? 0 : 1;
        $this->products->updateRecord($id, ['is_active' => $newState]);

        Audit::log('Update', 'Loan Products', ($newState ? 'Activated' : 'Deactivated') . ' loan product ' . $product['product_code'] . '.');
        Session::flash('success', 'Loan product ' . ($newState ? 'activated' : 'deactivated') . '.');
        $this->redirect('/loan-products');
    }

    public function togglePlanActive(string $planId): void
    {
        Auth::authorize('loans.edit');
        $planId = (int) $planId;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loan-products');
            return;
        }

        $plan = $this->products->findPlan($planId);
        if (!$plan) {
            Session::flash('error', 'Plan not found.');
            $this->redirect('/loan-products');
            return;
        }

        $newState = $plan['is_active'] ? 0 : 1;
        $this->products->updatePlan($planId, ['is_active' => $newState]);

        Audit::log('Update', 'Loan Products', ($newState ? 'Activated' : 'Deactivated') . ' plan #' . $planId . '.');
        Session::flash('success', 'Plan ' . ($newState ? 'activated' : 'deactivated') . '.');
        $this->redirect('/loan-products');
    }
}
