<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Models\DebitOrderCancellation;
use App\Models\Loan;
use App\Support\CollexiaCodes;

/**
 * Registers a borrower's recurring debit order mandate against a loan. This
 * is a lightweight mandate record only -- batch bank-file collection
 * processing (debit_order_runs/debit_order_run_lines) is a separate, much
 * larger integration and is out of scope here.
 */
class DebitOrderController extends Controller
{
    private DebitOrder $debitOrders;
    private DebitOrderCancellation $cancellations;
    private Loan $loans;
    private Borrower $borrowers;
    private CollexiaSetting $collexiaSettings;

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->cancellations = new DebitOrderCancellation();
        $this->loans = new Loan();
        $this->borrowers = new Borrower();
        $this->collexiaSettings = new CollexiaSetting();
    }

    /** Hard scope -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow the list via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    /** Redirects away (404-style) if the record's loan belongs to another branch and the viewer isn't Super Admin. */
    private function assertBranchAccess(?array $record, string $notFoundRedirect = '/debit-orders'): void
    {
        if (!$record || Auth::isSuperAdmin()) {
            return;
        }
        $recordBranchId = $record['loan_branch_id'] ?? $record['branch_id'] ?? 0;
        if ((int) $recordBranchId !== (int) Auth::branchId()) {
            Session::flash('error', 'Record not found.');
            $this->redirect($notFoundRedirect);
        }
    }

    public function index(): void
    {
        Auth::authorize('collections.debit_orders');
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('debit_orders/index', [
            'title' => 'Debit Orders',
            'debitOrders' => $this->debitOrders->paginated($status, $this->indexBranchId()),
            'status' => $status,
        ]);
    }

    public function create(string $loanId): void
    {
        Auth::authorize('collections.debit_orders');
        $loan = $this->loans->find((int) $loanId);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }
        $this->assertBranchAccess($loan, '/loans');

        $existing = $this->debitOrders->liveForLoan((int) $loanId);
        if ($existing) {
            Session::flash('error', 'This loan already has a ' . strtolower($existing['status']) . ' debit order (' . $existing['debit_order_no'] . '). Cancel or suspend it before registering a new one -- Collexia collects every active mandate independently, so a second one means the client gets deducted twice.');
            $this->redirect('/debit-orders/' . $existing['id']);
            return;
        }

        $this->view('debit_orders/create', [
            'title' => 'Register Debit Order - ' . $loan['loan_no'],
            'loan' => $loan,
            'old' => $this->prefillFromBankDetails((int) $loan['borrower_id']),
            'errors' => [],
            'banks' => CollexiaCodes::BANKS,
            'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
        ]);
    }

    /**
     * Pulls the borrower's bank details (captured at intake -- either typed
     * on the borrower profile's Banking tab, or copied over automatically
     * when an approved application was converted to a borrower) into the
     * same $old array the view already reads via old($field, $old) for
     * validation-failure repopulation, so staff don't have to retype
     * information that's already on file. account_name/account_number/
     * branch_code copy over directly; bank_code and account_type are stored
     * as free text at intake but the form needs Collexia's fixed codes, so
     * they're normalized here with a graceful fallback to blank/default
     * when nothing matches (staff can still pick manually).
     */
    private function prefillFromBankDetails(int $borrowerId): array
    {
        $bank = $this->borrowers->bankDetails($borrowerId);
        if (!$bank) {
            return [];
        }

        $old = [
            'account_name' => $bank['account_name'] ?? '',
            'account_number' => $bank['account_number'] ?? '',
            'branch_code' => $bank['branch_code'] ?? '',
        ];

        $bankName = trim((string) ($bank['bank_name'] ?? ''));
        if ($bankName !== '') {
            foreach (CollexiaCodes::BANKS as $code => $label) {
                if (stripos($label, $bankName) !== false || stripos($bankName, $label) !== false) {
                    $old['bank_code'] = $code;
                    break;
                }
            }
        }

        $accountType = trim((string) ($bank['account_type'] ?? ''));
        if ($accountType !== '') {
            $old['account_type'] = stripos($accountType, 'saving') !== false ? '2' : '1';
        }

        return $old;
    }

    public function store(): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans');
            return;
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }
        $this->assertBranchAccess($loan, '/loans');

        $existing = $this->debitOrders->liveForLoan($loanId);
        if ($existing) {
            Session::flash('error', 'This loan already has a ' . strtolower($existing['status']) . ' debit order (' . $existing['debit_order_no'] . '). Cancel or suspend it before registering a new one.');
            $this->redirect('/debit-orders/' . $existing['id']);
            return;
        }

        $errors = [];
        foreach (['bank_code', 'account_number', 'debit_day', 'debit_amount', 'start_date'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }
        if (!empty($_POST['bank_code']) && !isset(CollexiaCodes::BANKS[$_POST['bank_code']])) {
            $errors['bank_code'] = 'Select a valid bank.';
        }

        if (!empty($errors)) {
            $this->view('debit_orders/create', [
                'title' => 'Register Debit Order - ' . $loan['loan_no'],
                'loan' => $loan,
                'old' => $_POST,
                'errors' => $errors,
                'banks' => CollexiaCodes::BANKS,
                'accountTypes' => CollexiaCodes::ACCOUNT_TYPES,
            ]);
            return;
        }

        $trackingDays = max(1, min(14, (int) ($_POST['no_of_days_tracking'] ?? 3)));

        $debitOrderId = $this->debitOrders->create([
            'borrower_id' => $loan['borrower_id'],
            'loan_id' => $loanId,
            'debit_order_no' => generate_reference('DO'),
            'bank_name' => CollexiaCodes::BANKS[$_POST['bank_code']],
            'account_name' => trim($_POST['account_name'] ?? '') ?: null,
            'account_number' => trim($_POST['account_number']),
            'branch_code' => trim($_POST['branch_code'] ?? '') ?: null,
            'debit_day' => (int) $_POST['debit_day'],
            'debit_amount' => (float) $_POST['debit_amount'],
            'start_date' => $_POST['start_date'],
            'status' => 'Active',
            // Collexia's EnDo IDType is always 1 (ID Number) for this business.
            'id_type' => 1,
            'account_type' => (int) ($_POST['account_type'] ?? 1),
            'bank_code' => $_POST['bank_code'],
            'no_of_days_tracking' => $trackingDays,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        // Merchant System Contract No forms part of Collexia's 30-char bank
        // statement reference and must be <=10 chars -- derived from our own
        // PK so it's guaranteed unique without asking staff to invent one.
        $this->debitOrders->updateRecord($debitOrderId, [
            'merchant_system_contract_no' => sprintf('SD%08d', $debitOrderId),
        ]);

        Audit::log('Create', 'Debit Orders', 'Registered debit order #' . $debitOrderId . ' for loan ' . $loan['loan_no']);
        Session::flash('success', 'Debit order registered.');
        $this->redirect('/debit-orders/' . $debitOrderId);
    }

    public function show(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = $this->debitOrders->find((int) $id);

        if (!$debitOrder) {
            Session::flash('error', 'Debit order not found.');
            $this->redirect('/debit-orders');
            return;
        }
        $this->assertBranchAccess($debitOrder);

        $this->view('debit_orders/show', [
            'title' => 'Debit Order ' . $debitOrder['debit_order_no'],
            'debitOrder' => $debitOrder,
            'pendingCancellation' => $this->cancellations->findPendingForDebitOrder((int) $id),
            'collexiaEnabled' => $this->collexiaSettings->isEnabled(),
            'collexiaConfigured' => $this->collexiaSettings->isConfigured(),
        ]);
    }
}
