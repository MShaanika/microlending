<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BankAccount;
use App\Models\CollexiaSetting;
use App\Models\DebitOrderCollection;
use App\Models\DebitOrderCollectionImport;
use App\Models\DebitOrderInstallmentTarget;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\CollexiaEndoApiClient;
use App\Services\CollexiaFailedValidationParser;
use App\Services\CollexiaMandateCreationAuditParser;
use App\Services\CollexiaMandateLookupService;
use App\Services\CollexiaPaymentReconciliationService;
use App\Services\CollexiaReportReader;
use App\Services\CollexiaScheduledInstallmentsDetailParser;
use App\Services\CollexiaScheduledInstallmentsForecastParser;
use App\Services\CollexiaScheduledInstallmentsParser;
use App\Services\CollexiaSuccessfulTransactionDetailParser;
use App\Services\CollexiaSuccessfulTransactionsParser;
use App\Services\CollexiaSuccessfulTransactionsSimplifiedParser;
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
    private DebitOrderInstallmentTarget $installmentTargets;
    private Loan $loans;
    private Payment $payments;
    private BankAccount $bankAccounts;
    private CollexiaSetting $collexiaSettings;
    private CollexiaMandateLookupService $mandateLookup;

    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct()
    {
        $this->imports = new DebitOrderCollectionImport();
        $this->collections = new DebitOrderCollection();
        $this->installmentTargets = new DebitOrderInstallmentTarget();
        $this->loans = new Loan();
        $this->payments = new Payment();
        $this->bankAccounts = new BankAccount();
        $this->collexiaSettings = new CollexiaSetting();
        $this->mandateLookup = new CollexiaMandateLookupService();
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
            Session::flash('error', 'Could not recognize this file as one of Collexia\'s report exports.');
            $this->redirect('/debit-order-collections/create');
            return;
        }

        $result = match ($reportType) {
            'Successful' => CollexiaSuccessfulTransactionsParser::parse($file['tmp_name']),
            'Unsuccessful' => CollexiaUnsuccessfulTransactionsParser::parse($file['tmp_name']),
            'Scheduled' => CollexiaScheduledInstallmentsParser::parse($file['tmp_name']),
            'FailedValidation' => CollexiaFailedValidationParser::parse($file['tmp_name']),
            'SuccessfulSimplified' => CollexiaSuccessfulTransactionsSimplifiedParser::parse($file['tmp_name']),
            'SuccessfulDetail' => CollexiaSuccessfulTransactionDetailParser::parse($file['tmp_name']),
            'ScheduledDetail' => CollexiaScheduledInstallmentsDetailParser::parse($file['tmp_name']),
            'ScheduledForecast' => CollexiaScheduledInstallmentsForecastParser::parse($file['tmp_name']),
            'MandateAudit' => CollexiaMandateCreationAuditParser::parse($file['tmp_name']),
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

        // Reports that carry real collection data (installment no + amount
        // + successful date) and post a Payment, same rules regardless of
        // which report supplied them. Every other report type is
        // visibility-only -- see each parser's own docblock for why.
        $postingTypes = ['Successful', 'SuccessfulDetail'];
        // 'SuccessfulSimplified' and 'ScheduledForecast' carry no contract-
        // reference-shaped column at all (see their parsers' docblocks) --
        // resolving a mandate from anything else they DO carry (a client/
        // loan reference) risks matching the wrong loan, so these two never
        // attempt a lookup at all.
        $unmatchableTypes = ['SuccessfulSimplified', 'ScheduledForecast'];

        foreach ($result['rows'] as $row) {
            $reference = (string) ($row['merchant_system_contract_no'] ?? '');
            // Resolves via legacy batch, API non-split, or a split leg's own
            // contract reference -- see CollexiaMandateLookupService's
            // docblock; a plain findByContractNo() here would silently miss
            // every payment for an API-placed, non-split mandate.
            $mandate = in_array($reportType, $unmatchableTypes, true) ? null : $this->mandateLookup->resolve($reference);
            $debitOrderId = $mandate['debit_order_id'] ?? null;
            $loanId = $mandate['loan_id'] ?? null;
            $splitNo = $mandate['split_no'] ?? null;
            $paymentId = null;

            if ($mandate) {
                $matched++;
            }

            if (in_array($reportType, $postingTypes, true)) {
                $installmentNo = (int) $row['installment_no'];
                $alreadyPosted = $mandate && $this->collections->alreadyPosted((int) $debitOrderId, $installmentNo, $splitNo);

                if ($mandate && !$alreadyPosted) {
                    $loan = $this->loans->find((int) $loanId);
                    if ($loan) {
                        $meta = [
                            'payment_date' => $row['successful_date'],
                            'payment_source' => 'Debit Order',
                            'bank_account_id' => $bankAccountId,
                            'reference_no' => $reference . '-' . $installmentNo . ($splitNo !== null ? '-' . $splitNo : ''),
                            'payer_name' => $loan['borrower_name'] ?? ($row['client_name'] ?? null),
                            'notes' => 'Collexia ' . $reportType . ' report: ' . $file['name'],
                            'user_id' => $userId,
                        ];

                        // A split's collection targets the exact
                        // loan_schedules row snapshotted at placement time
                        // (DebitOrderInstallmentTarget), same as the REST
                        // Download Payments path -- otherwise
                        // recordAndAllocate()'s FIFO could land it on the
                        // wrong row whenever the loan has arrears ahead of
                        // the current installment.
                        if ($splitNo !== null) {
                            $scheduleId = $this->installmentTargets->scheduleIdFor((int) $debitOrderId, $installmentNo);
                            if ($scheduleId) {
                                $paymentId = $this->payments->recordAndAllocateToScheduleId($loan, $scheduleId, (float) $row['collection_amount'], $meta);
                                $posted++;
                            }
                        } else {
                            $paymentId = $this->payments->recordAndAllocate($loan, (float) $row['collection_amount'], $meta);
                            $posted++;
                        }
                    }
                }

                $this->collections->create([
                    'import_id' => $importId,
                    'debit_order_id' => $debitOrderId,
                    'loan_id' => $loanId,
                    'merchant_system_contract_no' => $reference,
                    'installment_no' => $row['installment_no'],
                    'split_no' => $splitNo,
                    'scheduled_date' => $row['scheduled_date'],
                    'installment_amount' => $row['installment_amount'],
                    'payment_date' => $row['successful_date'],
                    'payment_amount' => $row['collection_amount'],
                    'installment_status' => 'Successful',
                    'matched' => $mandate ? 1 : 0,
                    'payment_id' => $paymentId,
                ]);
            } else {
                // Every other report type -- rejection reasons, broad
                // status snapshots, forecasts, and mandate-creation audit
                // rows -- is visibility-only, never posts a payment. Some
                // (ScheduledDetail) carry a Payment Date/Amount of their
                // own, which are recorded here for reference even though
                // they never drive posting -- see that parser's docblock
                // for why.
                $this->collections->create([
                    'import_id' => $importId,
                    'debit_order_id' => $debitOrderId,
                    'loan_id' => $loanId,
                    // merchant_system_contract_no is sized for a contract
                    // reference (<=14 chars) -- SuccessfulSimplified has no
                    // such column, only a shorter client/loan reference
                    // that happens to fit the same column for display; its
                    // own longer StatementReference is deliberately NOT
                    // used here, it would silently overflow.
                    'merchant_system_contract_no' => $reference !== '' ? $reference : ($row['merchant_client_no'] ?? $row['client_number'] ?? null),
                    'installment_no' => $row['installment_no'] ?? null,
                    'split_no' => $splitNo,
                    'scheduled_date' => $row['scheduled_date'] ?? null,
                    'installment_amount' => $row['installment_amount'] ?? null,
                    'payment_date' => $row['payment_date'] ?? null,
                    'payment_amount' => $row['payment_amount'] ?? null,
                    'installment_status' => $row['installment_status'] ?? null,
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
