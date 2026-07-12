<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Services\DepreciationService;

class AssetController extends Controller
{
    private FixedAsset $assets;
    private AssetCategory $categories;
    private Branch $branches;

    public function __construct()
    {
        $this->assets = new FixedAsset();
        $this->categories = new AssetCategory();
        $this->branches = new Branch();
    }

    public function index(): void
    {
        Auth::authorize('assets.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('assets/index', [
            'title' => 'Fixed Assets',
            'assets' => $this->assets->paginated($search, $status),
            'totals' => $this->assets->totals(),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('assets.manage');
        $this->view('assets/create', [
            'title' => 'Register Asset',
            'categories' => $this->categories->activeCategories(),
            'branches' => $this->branches->all(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('assets.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/fixed-assets/create');
        }

        $errors = [];
        foreach (['category_id', 'asset_name', 'purchase_date', 'purchase_cost', 'useful_life_months', 'depreciation_start_date'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }

        $category = $this->categories->find((int) ($_POST['category_id'] ?? 0));
        if (!$category) {
            $errors['category_id'] = 'Select a valid category.';
        }

        if (!empty($errors)) {
            $this->view('assets/create', [
                'title' => 'Register Asset',
                'categories' => $this->categories->activeCategories(),
                'branches' => $this->branches->all(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $purchaseCost = (float) $_POST['purchase_cost'];
        $additionalCosts = (float) ($_POST['additional_costs'] ?? 0);
        $capitalizedCost = round($purchaseCost + $additionalCosts, 2);
        $residualValue = (float) ($_POST['residual_value'] ?? 0);
        $usefulLifeMonths = (int) $_POST['useful_life_months'];
        $method = $_POST['depreciation_method'] ?: $category['depreciation_method'];
        $reducingRate = $_POST['reducing_balance_rate'] !== '' ? (float) $_POST['reducing_balance_rate'] : $category['default_reducing_balance_rate'];

        $assetId = $this->assets->create([
            'branch_id' => $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null,
            'category_id' => (int) $category['id'],
            'asset_no' => generate_reference('AST'),
            'asset_name' => trim($_POST['asset_name']),
            'asset_nature' => $category['asset_nature'],
            'description' => trim($_POST['description'] ?? '') ?: null,
            'serial_no' => trim($_POST['serial_no'] ?? '') ?: null,
            'location' => trim($_POST['location'] ?? '') ?: null,
            'supplier_name' => trim($_POST['supplier_name'] ?? '') ?: null,
            'purchase_date' => $_POST['purchase_date'],
            'purchase_cost' => $purchaseCost,
            'additional_costs' => $additionalCosts,
            'capitalized_cost' => $capitalizedCost,
            'residual_value' => $residualValue,
            'useful_life_months' => $usefulLifeMonths,
            'depreciation_method' => $method,
            'reducing_balance_rate' => $reducingRate !== null ? (float) $reducingRate : null,
            'depreciation_start_date' => $_POST['depreciation_start_date'],
            'accumulated_depreciation' => 0,
            'net_book_value' => $capitalizedCost,
            'status' => 'Active',
            'asset_account_id' => $category['asset_account_id'],
            'depreciation_expense_account_id' => $category['depreciation_expense_account_id'],
            'accumulated_depreciation_account_id' => $category['accumulated_depreciation_account_id'],
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        $rows = DepreciationService::generate(
            $capitalizedCost,
            $residualValue,
            $usefulLifeMonths,
            $method,
            $reducingRate !== null ? (float) $reducingRate : null,
            $_POST['depreciation_start_date']
        );
        $this->assets->insertScheduleRows($assetId, $rows);

        $label = $category['asset_nature'] === 'Intangible' ? 'amortization' : 'depreciation';
        Audit::log('Create', 'Assets', "Registered asset #$assetId with a $label schedule of " . count($rows) . ' periods.');
        Session::flash('success', ucfirst($label) . ' schedule generated for the new asset.');
        $this->redirect('/fixed-assets/' . $assetId);
    }

    public function show(string $id): void
    {
        Auth::authorize('assets.view');
        $asset = $this->assets->find((int) $id);

        if (!$asset) {
            Session::flash('error', 'Asset not found.');
            $this->redirect('/fixed-assets');
        }

        $this->view('assets/show', [
            'title' => $asset['asset_name'],
            'asset' => $asset,
            'schedule' => $this->assets->schedule((int) $id),
        ]);
    }

    public function depreciate(string $id): void
    {
        Auth::authorize('assets.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/fixed-assets/' . $id);
        }

        $existing = $this->assets->find($id);
        if (!$existing || $existing['status'] !== 'Active') {
            Session::flash('error', 'Only active assets can have a period posted.');
            $this->redirect('/fixed-assets/' . $id);
        }

        $result = $this->assets->depreciateNextPeriod($id, Auth::user()['id'] ?? null);

        if (!$result) {
            Session::flash('error', 'No pending depreciation/amortization period for this asset.');
            $this->redirect('/fixed-assets/' . $id);
        }

        Audit::log('Post', 'Assets', 'Posted depreciation period #' . $result['period']['period_no'] . ' for asset #' . $id . ' via journal #' . $result['journal_id']);
        Session::flash('success', 'Period ' . $result['period']['period_no'] . ' posted for ' . format_money($result['period']['depreciation_amount']) . '.');
        $this->redirect('/fixed-assets/' . $id);
    }

    public function dispose(string $id): void
    {
        Auth::authorize('assets.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/fixed-assets/' . $id);
        }

        $asset = $this->assets->find($id);
        if (!$asset || $asset['status'] === 'Disposed') {
            Session::flash('error', 'Asset not found or already disposed.');
            $this->redirect('/fixed-assets');
        }

        $proceeds = (float) ($_POST['disposal_proceeds'] ?? 0);
        $nbv = (float) $asset['net_book_value'];
        $gainLoss = round($proceeds - $nbv, 2);

        $this->assets->insertDisposal([
            'asset_id' => $id,
            'disposal_date' => $_POST['disposal_date'] ?: date('Y-m-d'),
            'disposal_method' => $_POST['disposal_method'] ?: 'Sold',
            'disposal_proceeds' => $proceeds,
            'net_book_value_at_disposal' => $nbv,
            'gain_loss_amount' => $gainLoss,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'disposed_by' => Auth::user()['id'] ?? null,
        ]);

        $this->assets->updateFields($id, ['status' => 'Disposed']);

        Audit::log('Dispose', 'Assets', 'Disposed asset #' . $id . ' (gain/loss ' . format_money($gainLoss) . ')');
        Session::flash('success', 'Asset disposed. Gain/loss of ' . format_money($gainLoss) . ' recorded.');
        $this->redirect('/fixed-assets/' . $id);
    }
}
