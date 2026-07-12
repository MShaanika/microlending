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
}
