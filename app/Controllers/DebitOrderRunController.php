<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\DebitOrder;
use App\Models\DebitOrderRun;
use App\Models\DebitOrderRunLine;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\DebitOrderResponseCsvParser;

/**
 * Batch debit order collection: sweep all Active mandates due in a month
 * into a run, export a CSV for the bank, import the bank's response CSV,
 * then post the successful lines as real Payments. No real bank file spec
 * exists yet -- see DebitOrderResponseCsvParser for the generic CSV format
 * this uses, which can be swapped later without changing this workflow.
 */
class DebitOrderRunController extends Controller
{
    private DebitOrderRun $runs;
    private DebitOrderRunLine $lines;
    private DebitOrder $debitOrders;
    private Branch $branches;
    private BankAccount $bankAccounts;
    private Loan $loans;
    private Payment $payments;

    private const ALLOWED_CSV_EXTENSIONS = ['csv'];
    private const MAX_CSV_SIZE = 5 * 1024 * 1024; // 5MB

    public function __construct()
    {
        $this->runs = new DebitOrderRun();
        $this->lines = new DebitOrderRunLine();
        $this->debitOrders = new DebitOrder();
        $this->branches = new Branch();
        $this->bankAccounts = new BankAccount();
        $this->loans = new Loan();
        $this->payments = new Payment();
    }

    public function index(): void
    {
        Auth::authorize('collections.debit_orders');
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('debit_order_runs/index', [
            'title' => 'Debit Order Runs',
            'runs' => $this->runs->paginated($status),
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('collections.debit_orders');
        $this->view('debit_order_runs/create', [
            'title' => 'New Debit Order Run',
            'branches' => $this->branches->all(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-runs/create');
            return;
        }

        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $debitMonth = trim($_POST['debit_month'] ?? '');

        if (!$branchId || !preg_match('/^\d{4}-\d{2}$/', $debitMonth)) {
            $this->view('debit_order_runs/create', [
                'title' => 'New Debit Order Run',
                'branches' => $this->branches->all(),
                'old' => $_POST,
                'errors' => ['debit_month' => 'Select a branch and a valid month.'],
            ]);
            return;
        }

        $matches = $this->debitOrders->activeForMonth($branchId, $debitMonth);

        if (empty($matches)) {
            Session::flash('error', 'No active debit orders are due for collection in that month.');
            $this->redirect('/debit-order-runs/create');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $totalAmount = array_sum(array_column($matches, 'debit_amount'));

        $runId = $this->runs->create([
            'branch_id' => $branchId,
            'run_no' => generate_reference('DOR'),
            'run_date' => date('Y-m-d'),
            'debit_month' => $debitMonth,
            'total_accounts' => count($matches),
            'total_amount' => $totalAmount,
            'status' => 'Draft',
        ]);

        foreach ($matches as $mandate) {
            $this->lines->create([
                'run_id' => $runId,
                'debit_order_id' => $mandate['id'],
                'borrower_id' => $mandate['borrower_id'],
                'loan_id' => $mandate['loan_id'],
                'debit_amount' => $mandate['debit_amount'],
                'bank_reference' => generate_reference('DOL'),
                'status' => 'Pending',
            ]);
        }

        Audit::log('Create', 'Debit Order Runs', 'Generated run #' . $runId . ' with ' . count($matches) . ' account(s) for ' . $debitMonth);
        Session::flash('success', 'Run created with ' . count($matches) . ' account(s). Export the collection file next.');
        $this->redirect('/debit-order-runs/' . $runId);
    }

    public function show(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $run = $this->runs->find((int) $id);

        if (!$run) {
            Session::flash('error', 'Run not found.');
            $this->redirect('/debit-order-runs');
            return;
        }

        $this->view('debit_order_runs/show', [
            'title' => 'Debit Order Run ' . $run['run_no'],
            'run' => $run,
            'lines' => $this->lines->forRun((int) $id),
            'bankAccounts' => $this->bankAccounts->allBankAccounts(true),
        ]);
    }

    /**
     * Streams the outgoing collection CSV. account_holder/bank_name/
     * account_number/branch_code come from the mandate (debit_orders),
     * amount/reference from the run line, loan_no for staff cross-checking.
     */
    public function export(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;
        $run = $this->runs->find($id);

        if (!$run) {
            Session::flash('error', 'Run not found.');
            $this->redirect('/debit-order-runs');
            return;
        }

        $lines = $this->lines->forRun($id);

        if ($run['status'] === 'Draft') {
            $this->runs->updateRecord($id, [
                'status' => 'Generated',
                'generated_by' => Auth::user()['id'] ?? null,
                'generated_at' => date('Y-m-d H:i:s'),
            ]);
            Audit::log('Generate', 'Debit Order Runs', 'Exported collection file for run #' . $id);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $run['run_no']) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['account_holder', 'bank_name', 'account_number', 'branch_code', 'amount', 'reference', 'loan_no'], ',', '"', '\\');

        foreach ($lines as $line) {
            $mandate = $this->debitOrders->find((int) $line['debit_order_id']);
            fputcsv($out, [
                $mandate['account_name'] ?? $line['borrower_name'],
                $mandate['bank_name'] ?? '',
                $mandate['account_number'] ?? '',
                $mandate['branch_code'] ?? '',
                number_format((float) $line['debit_amount'], 2, '.', ''),
                $line['bank_reference'],
                $line['loan_no'],
            ], ',', '"', '\\');
        }

        fclose($out);
        exit;
    }

    public function submit(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $run = $this->runs->find($id);
        if (!$run || $run['status'] !== 'Generated') {
            Session::flash('error', 'Only a generated run can be marked as submitted.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $this->runs->updateRecord($id, ['status' => 'Submitted']);
        Audit::log('Submit', 'Debit Order Runs', 'Marked run #' . $id . ' as submitted to the bank');
        Session::flash('success', 'Run marked as submitted. Import the bank\'s response file once it comes back.');
        $this->redirect('/debit-order-runs/' . $id);
    }

    public function importResponseForm(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $run = $this->runs->find((int) $id);

        if (!$run || $run['status'] !== 'Submitted') {
            Session::flash('error', 'Only a submitted run can have its response file imported.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $this->view('debit_order_runs/import_response', [
            'title' => 'Import Response - ' . $run['run_no'],
            'run' => $run,
            'errors' => [],
        ]);
    }

    public function importResponse(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $run = $this->runs->find($id);
        if (!$run || $run['status'] !== 'Submitted') {
            Session::flash('error', 'Only a submitted run can have its response file imported.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $file = $_FILES['response_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            Session::flash('error', 'Choose a CSV file to import.');
            $this->redirect('/debit-order-runs/' . $id . '/import-response');
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Upload failed. Please try again.');
            $this->redirect('/debit-order-runs/' . $id . '/import-response');
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['size'] > self::MAX_CSV_SIZE) {
            Session::flash('error', 'File is too large (max 5MB).');
            $this->redirect('/debit-order-runs/' . $id . '/import-response');
            return;
        }
        if (!in_array($ext, self::ALLOWED_CSV_EXTENSIONS, true)) {
            Session::flash('error', 'Only CSV files are accepted.');
            $this->redirect('/debit-order-runs/' . $id . '/import-response');
            return;
        }

        $result = DebitOrderResponseCsvParser::parse($file['tmp_name']);

        if (empty($result['rows']) && !empty($result['errors'])) {
            Session::flash('error', 'Import failed: ' . implode(' ', $result['errors']));
            $this->redirect('/debit-order-runs/' . $id . '/import-response');
            return;
        }

        $matched = 0;
        $unmatched = 0;

        foreach ($result['rows'] as $row) {
            $line = $this->lines->findByReference($id, $row['reference']);
            if (!$line) {
                $unmatched++;
                continue;
            }

            $this->lines->updateRecord((int) $line['id'], [
                'status' => $row['status'],
                'response_code' => $row['response_code'] ?: null,
                'response_message' => $row['response_message'] ?: null,
            ]);
            $matched++;
        }

        if ($this->lines->pendingCount($id) === 0) {
            $this->runs->updateRecord($id, ['status' => 'Processed']);
        }

        Audit::log('Import', 'Debit Order Runs', 'Imported response file for run #' . $id . ' (' . $matched . ' matched, ' . $unmatched . ' unmatched)');

        $message = "Imported: $matched line(s) updated.";
        if ($unmatched > 0) {
            $message .= " $unmatched row(s) did not match any line in this run.";
        }
        if (!empty($result['errors'])) {
            $message .= ' ' . count($result['errors']) . ' row(s) skipped: ' . implode(' ', array_slice($result['errors'], 0, 5));
        }
        Session::flash('success', $message);
        $this->redirect('/debit-order-runs/' . $id);
    }

    /**
     * Posts every Successful, not-yet-posted line as a real Payment against
     * its loan. Each Payment::recordAndAllocate() call posts its own
     * accounting journal -- there is no separate batch-level journal entry.
     */
    public function post(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $run = $this->runs->find($id);
        if (!$run || $run['status'] !== 'Processed') {
            Session::flash('error', 'Only a processed run can be posted.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $bankAccountId = (int) ($_POST['bank_account_id'] ?? 0) ?: null;
        $userId = Auth::user()['id'] ?? null;
        $posted = 0;

        foreach ($this->lines->unpostedSuccessful($id) as $line) {
            $loan = $this->loans->find((int) $line['loan_id']);
            if (!$loan) {
                continue;
            }

            $paymentId = $this->payments->recordAndAllocate($loan, (float) $line['debit_amount'], [
                'payment_date' => date('Y-m-d'),
                'payment_source' => 'Debit Order',
                'bank_account_id' => $bankAccountId,
                'reference_no' => $line['bank_reference'],
                'payer_name' => $loan['borrower_name'] ?? null,
                'notes' => 'Debit order run ' . $run['run_no'],
                'user_id' => $userId,
            ]);

            $this->lines->updateRecord((int) $line['id'], [
                'status' => 'Posted',
                'payment_id' => $paymentId,
            ]);
            $posted++;
        }

        $this->runs->updateRecord($id, [
            'status' => 'Posted',
            'posted_by' => $userId,
            'posted_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Post', 'Debit Order Runs', 'Posted run #' . $id . ' (' . $posted . ' payment(s) recorded)');
        Session::flash('success', $posted . ' payment(s) posted from this run.');
        $this->redirect('/debit-order-runs/' . $id);
    }

    public function cancel(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $run = $this->runs->find($id);
        if (!$run || !in_array($run['status'], ['Draft', 'Generated'], true)) {
            Session::flash('error', 'Only a draft or generated run can be cancelled.');
            $this->redirect('/debit-order-runs/' . $id);
            return;
        }

        $this->runs->updateRecord($id, ['status' => 'Cancelled']);
        Audit::log('Cancel', 'Debit Order Runs', 'Cancelled run #' . $id);
        Session::flash('success', 'Run cancelled.');
        $this->redirect('/debit-order-runs/' . $id);
    }
}
