<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Services\DepreciationService;

class AssetController extends Controller
{
    private FixedAsset $assets;
    private AssetCategory $categories;
    private Branch $branches;
    private BankAccount $bankAccounts;
    private AccountingAccount $accounts;
    private AccountingJournal $journal;

    public function __construct()
    {
        $this->assets = new FixedAsset();
        $this->categories = new AssetCategory();
        $this->branches = new Branch();
        $this->bankAccounts = new BankAccount();
        $this->accounts = new AccountingAccount();
        $this->journal = new AccountingJournal();
    }

    /** Hard scope -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    /** Redirects away (404-style) if the asset belongs to another branch and the viewer isn't Super Admin.
     *  An unassigned (branch_id NULL) asset is shared/company-wide and always visible. */
    private function assertBranchAccess(?array $asset): void
    {
        if (!$asset || Auth::isSuperAdmin() || $asset['branch_id'] === null) {
            return;
        }
        if ((int) $asset['branch_id'] !== (int) Auth::branchId()) {
            Session::flash('error', 'Asset not found.');
            $this->redirect('/fixed-assets');
        }
    }

    private function scopedBranches(?int $scopeBranchId): array
    {
        return $scopeBranchId === null ? $this->branches->all() : array_values(array_filter($this->branches->all(), fn($b) => (int) $b['id'] === $scopeBranchId));
    }

    public function index(): void
    {
        Auth::authorize('assets.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $branchId = $this->indexBranchId();

        $this->view('assets/index', [
            'title' => 'Fixed Assets',
            'assets' => $this->assets->paginated($search, $status, 100, $branchId),
            'totals' => $this->assets->totals($branchId),
            'search' => $search,
            'status' => $status,
            'branches' => Auth::isSuperAdmin() ? $this->branches->all() : [],
            'selectedBranchId' => $branchId,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('assets.manage');
        $this->view('assets/create', [
            'title' => 'Register Asset',
            'categories' => $this->categories->activeCategories(),
            'branches' => $this->scopedBranches($this->scopeBranchId()),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
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

        // 'none' = opening balance / already in the books (no journal);
        // '' = default cash/bank GL (1010); numeric = a specific bank account.
        $paidFrom = (string) ($_POST['paid_from'] ?? '');
        if ($paidFrom !== 'none' && $category && empty($category['asset_account_id'])) {
            $errors['paid_from'] = 'This category has no GL asset account configured, so no acquisition journal can be posted. Configure the category first, or select "No journal".';
        }

        if (!empty($errors)) {
            $this->view('assets/create', [
                'title' => 'Register Asset',
                'categories' => $this->categories->activeCategories(),
                'branches' => $this->scopedBranches($this->scopeBranchId()),
                'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
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

        $scopeBranchId = $this->scopeBranchId();
        // Non-Super-Admin can only register an asset to their own branch --
        // leaving it unassigned/shared is a company-wide decision.
        $branchId = $scopeBranchId !== null ? $scopeBranchId : ($_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null);

        $assetId = $this->assets->create([
            'branch_id' => $branchId,
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

        // Acquisition journal: Dr the category's asset account for the
        // capitalized cost, Cr the paying bank's GL account -- skipped for
        // opening-balance assets already reflected in the books.
        $journalId = null;
        $bankAccountId = null;
        if ($paidFrom !== 'none') {
            $bankAccountId = ctype_digit($paidFrom) ? (int) $paidFrom : null;
            $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;
            $bankGlAccount = $bankAccount ? (int) $bankAccount['account_id'] : $this->accounts->idByCode('1010');
            $bankLabel = $bankAccount ? $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'] : 'Cash/Bank';
            $assetNo = $this->assets->find($assetId)['asset_no'];

            try {
                $journalId = $this->journal->post(
                    'ASSET_ACQUIRED',
                    'fixed_assets',
                    $assetId,
                    $assetNo,
                    'Asset acquired: ' . trim($_POST['asset_name']),
                    [
                        ['account_id' => (int) $category['asset_account_id'], 'debit' => $capitalizedCost, 'credit' => 0, 'description' => $category['category_name'] . ' - ' . $assetNo],
                        ['account_id' => $bankGlAccount, 'debit' => 0, 'credit' => $capitalizedCost, 'description' => 'Paid from ' . $bankLabel],
                    ],
                    Auth::user()['id'] ?? null,
                    $_POST['purchase_date']
                );
            } catch (\RuntimeException $e) {
                // Roll the asset back rather than leaving a half-captured
                // record with no matching ledger entry.
                $this->assets->deleteRecord($assetId);
                Session::flash('error', 'Asset not registered: ' . $e->getMessage());
                $this->redirect('/fixed-assets/create');
                return;
            }

            $this->assets->updateFields($assetId, [
                'journal_id' => $journalId,
                'bank_account_id' => $bankAccountId,
            ]);
        }

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
        Audit::log('Create', 'Assets', "Registered asset #$assetId with a $label schedule of " . count($rows) . ' periods.' . ($journalId ? " Acquisition journal #$journalId posted." : ''));
        Session::flash('success', ucfirst($label) . ' schedule generated for the new asset.' . ($journalId ? ' Acquisition journal posted to the ledger.' : ''));
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
        $this->assertBranchAccess($asset);

        $this->view('assets/show', [
            'title' => $asset['asset_name'],
            'asset' => $asset,
            'schedule' => $this->assets->schedule((int) $id),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
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
        $this->assertBranchAccess($existing);

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
        $this->assertBranchAccess($asset);

        $proceeds = (float) ($_POST['disposal_proceeds'] ?? 0);
        $nbv = (float) $asset['net_book_value'];
        $gainLoss = round($proceeds - $nbv, 2);
        $accumDepr = (float) $asset['accumulated_depreciation'];
        $cost = (float) $asset['capitalized_cost'];

        // Balanced disposal journal: Dr the proceeds bank account and the
        // asset's accumulated depreciation (clearing both out), Cr the
        // asset's cost account, with the balancing gain/loss on the
        // remaining side. See AssetController::store() for the mirror-image
        // acquisition journal.
        $disposedInto = (string) ($_POST['received_into'] ?? '');
        $bankAccountId = ctype_digit($disposedInto) ? (int) $disposedInto : null;
        $bankAccount = $bankAccountId ? $this->bankAccounts->find($bankAccountId) : null;
        $bankGlAccount = $bankAccount ? (int) $bankAccount['account_id'] : $this->accounts->idByCode('1010');
        $bankLabel = $bankAccount ? $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'] : 'Cash/Bank';

        $lines = [];
        if ($proceeds > 0) {
            $lines[] = ['account_id' => $bankGlAccount, 'debit' => $proceeds, 'credit' => 0, 'description' => 'Disposal proceeds into ' . $bankLabel];
        }
        if ($accumDepr > 0) {
            $lines[] = ['account_id' => (int) $asset['accumulated_depreciation_account_id'], 'debit' => $accumDepr, 'credit' => 0, 'description' => 'Clear accumulated depreciation - ' . $asset['asset_no']];
        }
        if ($gainLoss > 0) {
            $lines[] = ['account_id' => $this->accounts->idByCode('4050'), 'debit' => 0, 'credit' => $gainLoss, 'description' => 'Gain on disposal - ' . $asset['asset_no']];
        } elseif ($gainLoss < 0) {
            $lines[] = ['account_id' => $this->accounts->idByCode('5235'), 'debit' => abs($gainLoss), 'credit' => 0, 'description' => 'Loss on disposal - ' . $asset['asset_no']];
        }
        $lines[] = ['account_id' => (int) $asset['asset_account_id'], 'debit' => 0, 'credit' => $cost, 'description' => 'Disposal of ' . $asset['asset_name'] . ' (' . $asset['asset_no'] . ')'];

        try {
            $journalId = $this->journal->post(
                'ASSET_DISPOSED',
                'fixed_assets',
                $id,
                $asset['asset_no'],
                'Asset disposed: ' . $asset['asset_name'],
                $lines,
                Auth::user()['id'] ?? null,
                $_POST['disposal_date'] ?: date('Y-m-d')
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', 'Asset not disposed: ' . $e->getMessage());
            $this->redirect('/fixed-assets/' . $id);
            return;
        }

        $this->assets->insertDisposal([
            'asset_id' => $id,
            'disposal_date' => $_POST['disposal_date'] ?: date('Y-m-d'),
            'disposal_method' => $_POST['disposal_method'] ?: 'Sold',
            'disposal_proceeds' => $proceeds,
            'net_book_value_at_disposal' => $nbv,
            'gain_loss_amount' => $gainLoss,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'journal_id' => $journalId,
            'disposed_by' => Auth::user()['id'] ?? null,
        ]);

        $this->assets->updateFields($id, ['status' => 'Disposed']);

        Audit::log('Dispose', 'Assets', 'Disposed asset #' . $id . ' (gain/loss ' . format_money($gainLoss) . ') via journal #' . $journalId);
        Session::flash('success', 'Asset disposed. Gain/loss of ' . format_money($gainLoss) . ' posted to the ledger.');
        $this->redirect('/fixed-assets/' . $id);
    }
}
