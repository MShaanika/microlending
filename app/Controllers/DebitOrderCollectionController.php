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
use App\Services\CollexiaReportReader;
use App\Services\CollexiaScheduledInstallmentsParser;
use App\Services\CollexiaSuccessfulTransactionsParser;
use App\Services\CollexiaUnsuccessfulTransactionsParser;

/**
 * Reconciles any of Collexia's three collection report exports against our
 * own mandates. Which parser runs is auto-detected from the file's sheet
 * name, since staff shouldn't have to know which report type they're
 * uploading:
 *  - Successful Transactions: the authoritative source of what was actually
 *    collected -- posts a real Payment against the matching loan, exactly
 *    once per installment even if the same or an overlapping report is
 *    imported again later.
 *  - Unsuccessful Transactions: failed collection attempts (e.g.
 *    Insufficient Funds) -- recorded for staff/collector follow-up, never
 *    posts a payment.
 *  - Scheduled Installments: a broad status snapshot across every
 *    installment, due or not -- carries no collection date/amount at all,
 *    so it's informational only.
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
            Session::flash('error', 'Choose a Collexia report .xlsx file to import.');
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

        $reportType = CollexiaReportReader::detectReportType($file['tmp_name']);
        if ($reportType === null) {
            Session::flash('error', 'Could not recognize this file as a Collexia Successful Transactions, Unsuccessful Transactions, or Scheduled Installments export.');
            $this->redirect('/debit-order-collections/create');
            return;
        }

        $result = match ($reportType) {
            'Successful' => CollexiaSuccessfulTransactionsParser::parse($file['tmp_name']),
            'Unsuccessful' => CollexiaUnsuccessfulTransactionsParser::parse($file['tmp_name']),
            default => CollexiaScheduledInstallmentsParser::parse($file['tmp_name']),
        };
        if (!empty($result['errors'])) {
            Session::flash('error', 'Import failed: ' . implode(' ', $result['errors']));
            $this->redirect('/debit-order-collections/create');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0) ?: null;

        $importId = $this->imports->create([
            'filename' => $file['name'],
            'report_type' => $reportType,
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
            }

            if ($reportType === 'Successful') {
                $alreadyPosted = $mandate && $this->collections->alreadyPosted((int) $debitOrderId, (int) $row['installment_no']);

                if ($mandate && !$alreadyPosted) {
                    $loan = $this->loans->find((int) $loanId);
                    if ($loan) {
                        $paymentId = $this->payments->recordAndAllocate($loan, (float) $row['collection_amount'], [
                            'payment_date' => $row['successful_date'],
                            'payment_source' => 'Debit Order',
                            'bank_account_id' => $bankAccountId,
                            'reference_no' => $row['merchant_system_contract_no'] . '-' . $row['installment_no'],
                            'payer_name' => $loan['borrower_name'] ?? $row['client_name'],
                            'notes' => 'Collexia Successful Transactions report: ' . $file['name'],
                            'user_id' => $userId,
                        ]);
                        $posted++;
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
                    'payment_date' => $row['successful_date'],
                    'payment_amount' => $row['collection_amount'],
                    'installment_status' => 'Successful',
                    'matched' => $mandate ? 1 : 0,
                    'payment_id' => $paymentId,
                ]);
            } else {
                // Unsuccessful (rejection reason in installment_status) and
                // Scheduled (broad status snapshot) both carry no collection
                // date/amount, so neither ever posts a payment -- recorded
                // purely for visibility.
                $this->collections->create([
                    'import_id' => $importId,
                    'debit_order_id' => $debitOrderId,
                    'loan_id' => $loanId,
                    'merchant_system_contract_no' => $row['merchant_system_contract_no'],
                    'installment_no' => $row['installment_no'],
                    'scheduled_date' => $row['scheduled_date'],
                    'installment_amount' => $row['installment_amount'],
                    'payment_date' => null,
                    'payment_amount' => null,
                    'installment_status' => $row['installment_status'],
                    'matched' => $mandate ? 1 : 0,
                    'payment_id' => null,
                ]);
            }
        }

        $this->imports->updateRecord($importId, [
            'matched_rows' => $matched,
            'posted_payments' => $posted,
        ]);

        Audit::log('Import', 'Debit Order Collections', 'Imported ' . $reportType . ' Transactions report ' . $file['name'] . ' (' . $matched . ' matched, ' . $posted . ' payment(s) posted)');
        Session::flash('success', count($result['rows']) . ' row(s) processed from the ' . $reportType . ' report: ' . $matched . ' matched to a mandate, ' . $posted . ' new payment(s) posted.');
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
