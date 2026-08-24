<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BankAccount;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Models\DebitOrderCollection;
use App\Models\DebitOrderCollectionImport;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\CollexiaEndoApiClient;
use App\Services\CollexiaPaymentReconciliationService;
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
 *
 * downloadPayments() is the REST-API equivalent of the same reconciliation,
 * pulling Collexia's own Download Payments response (spec 7.4) directly
 * instead of a manually-uploaded Successful Transactions file -- both paths
 * end up in the exact same debit_order_collection_imports/
 * debit_order_collections tables and this same review screen, distinguished
 * only by report_type = 'CollexiaAPI'. See CollexiaPaymentReconciliationService
 * for the actual matching/posting logic, and bin/download_collexia_payments.php
 * for the cron entry point that calls this on a schedule.
 */
class DebitOrderCollectionController extends Controller
{
    private DebitOrderCollectionImport $imports;
    private DebitOrderCollection $collections;
    private DebitOrder $debitOrders;
    private Loan $loans;
    private Payment $payments;
    private BankAccount $bankAccounts;
    private CollexiaSetting $collexiaSettings;

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
        $this->collexiaSettings = new CollexiaSetting();
    }

    public function index(): void
    {
        Auth::authorize('collections.debit_orders');
        $this->view('debit_order_collections/index', [
            'title' => 'Collection Reports',
            'imports' => $this->imports->paginated(),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
            'collexiaEnabled' => $this->collexiaSettings->isEnabled(),
            'collexiaConfigured' => $this->collexiaSettings->isConfigured(),
        ]);
    }

    /**
     * Pulls Collexia's Download Payments response right now (rather than
     * waiting for the next cron run) and reconciles it -- same posting
     * rules as the Successful Transactions upload, just sourced live from
     * the API. Manual trigger for an ad-hoc check; bin/download_collexia_payments.php
     * is the scheduled equivalent.
     */
    public function downloadPayments(): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-collections');
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0) ?: null;
        $userId = Auth::user()['id'] ?? null;

        try {
            $client = new CollexiaEndoApiClient();
            $response = $client->downloadPayments();
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/debit-order-collections');
            return;
        }

        $result = (new CollexiaPaymentReconciliationService())->reconcile($response, $userId, $bankAccountId);

        Audit::log('Import', 'Debit Order Collections', 'Downloaded Collexia payment results: ' . $result['total'] . ' row(s), ' . $result['matched'] . ' matched, ' . $result['posted'] . ' payment(s) posted');
        Session::flash('success', $result['total'] . ' row(s) downloaded from Collexia: ' . $result['matched'] . ' matched to a mandate, ' . $result['posted'] . ' new payment(s) posted.');
        $this->redirect('/debit-order-collections/' . $result['import_id']);
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
            Session::flash('error', 'Choose a report .xlsx file to import.');
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
            Session::flash('error', 'Could not recognize this file as a Successful Transactions, Unsuccessful Transactions, or Scheduled Installments export.');
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
