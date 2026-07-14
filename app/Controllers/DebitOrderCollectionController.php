<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BankAccount;
use App\Models\DebitOrder;
use App\Models\DebitOrderCollection;
use App\Models\DebitOrderCollectionImport;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\CollexiaScheduledInstallmentsParser;

/**
 * Reconciles Collexia's "Scheduled Installments" report against our own
 * mandates: for every row that shows a Payment Date/Amount (meaning
 * Collexia actually collected it), post a real Payment against the
 * matching loan -- exactly once per installment, even if the same or an
 * overlapping report is imported again later.
 */
class DebitOrderCollectionController extends Controller
{
    private DebitOrderCollectionImport $imports;
    private DebitOrderCollection $collections;
    private DebitOrder $debitOrders;
    private Loan $loans;
    private Payment $payments;
    private BankAccount $bankAccounts;

    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct()
    {
        $this->imports = new DebitOrderCollectionImport();
        $this->collections = new DebitOrderCollection();
        $this->debitOrders = new DebitOrder();
        $this->loans = new Loan();
        $this->payments = new Payment();
        $this->bankAccounts = new BankAccount();
    }

    public function index(): void
    {
        Auth::authorize('collections.debit_orders');
        $this->view('debit_order_collections/index', [
            'title' => 'Collexia Collection Reports',
            'imports' => $this->imports->paginated(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('collections.debit_orders');
        $this->view('debit_order_collections/create', [
            'title' => 'Import Collection Report',
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-collections/create');
            return;
        }

        $file = $_FILES['report_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            Session::flash('error', 'Choose the Scheduled Installments .xlsx file to import.');
            $this->redirect('/debit-order-collections/create');
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Upload failed. Please try again.');
            $this->redirect('/debit-order-collections/create');
            return;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['size'] > self::MAX_FILE_SIZE || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            Session::flash('error', 'Only .xlsx/.xls files up to 10MB are accepted.');
            $this->redirect('/debit-order-collections/create');
            return;
        }

        $result = CollexiaScheduledInstallmentsParser::parse($file['tmp_name']);
        if (!empty($result['errors'])) {
            Session::flash('error', 'Import failed: ' . implode(' ', $result['errors']));
            $this->redirect('/debit-order-collections/create');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0) ?: null;

        $importId = $this->imports->create([
            'filename' => $file['name'],
            'total_rows' => count($result['rows']),
            'imported_by' => $userId,
        ]);

        $matched = 0;
        $posted = 0;

        foreach ($result['rows'] as $row) {
            $mandate = $this->debitOrders->findByContractNo($row['merchant_system_contract_no']);
            $debitOrderId = $mandate['id'] ?? null;
            $loanId = $mandate['loan_id'] ?? null;
            $paymentId = null;

            if ($mandate) {
                $matched++;

                $collected = $row['payment_date'] !== null && $row['payment_amount'] !== null;
                $alreadyPosted = $collected && $this->collections->alreadyPosted((int) $debitOrderId, (int) $row['installment_no']);

                if ($collected && !$alreadyPosted) {
                    $loan = $this->loans->find((int) $loanId);
                    if ($loan) {
                        $paymentId = $this->payments->recordAndAllocate($loan, (float) $row['payment_amount'], [
                            'payment_date' => $row['payment_date'],
                            'payment_source' => 'Debit Order',
                            'bank_account_id' => $bankAccountId,
                            'reference_no' => $row['merchant_system_contract_no'] . '-' . $row['installment_no'],
                            'payer_name' => $loan['borrower_name'] ?? null,
                            'notes' => 'Collexia collection report: ' . $file['name'],
                            'user_id' => $userId,
                        ]);
                        $posted++;
                    }
                }
            }

            $this->collections->create([
                'import_id' => $importId,
                'debit_order_id' => $debitOrderId,
                'loan_id' => $loanId,
                'merchant_system_contract_no' => $row['merchant_system_contract_no'],
                'installment_no' => $row['installment_no'],
                'scheduled_date' => $row['scheduled_date'],
                'installment_amount' => $row['installment_amount'],
                'payment_date' => $row['payment_date'],
                'payment_amount' => $row['payment_amount'],
                'installment_status' => $row['installment_status'],
                'matched' => $mandate ? 1 : 0,
                'payment_id' => $paymentId,
            ]);
        }

        $this->imports->updateRecord($importId, [
            'matched_rows' => $matched,
            'posted_payments' => $posted,
        ]);

        Audit::log('Import', 'Debit Order Collections', 'Imported collection report ' . $file['name'] . ' (' . $matched . ' matched, ' . $posted . ' payment(s) posted)');
        Session::flash('success', count($result['rows']) . ' row(s) processed: ' . $matched . ' matched to a mandate, ' . $posted . ' new payment(s) posted.');
        $this->redirect('/debit-order-collections/' . $importId);
    }

    public function show(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $import = $this->imports->find((int) $id);

        if (!$import) {
            Session::flash('error', 'Import not found.');
            $this->redirect('/debit-order-collections');
            return;
        }

        $this->view('debit_order_collections/show', [
            'title' => 'Collection Report - ' . $import['filename'],
            'import' => $import,
            'rows' => $this->collections->forImport((int) $id),
        ]);
    }
}
