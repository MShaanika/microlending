<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\DebitOrder;
use App\Models\DebitOrderRun;
use App\Models\DebitOrderRunLine;
use App\Services\CollexiaEndoExporter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Registers newly-added debit order mandates with Collexia in a batch: an
 * EnDo Batch v1.0 workbook covering each mandate's full remaining
 * installment count. This is a ONE-TIME registration per mandate --
 * Collexia collects every period on its own from here, so a run never
 * needs to be resubmitted monthly. Reconciling what Collexia actually
 * collected is handled separately by DebitOrderCollectionController, which
 * imports Collexia's own "Scheduled Installments" report.
 */
class DebitOrderRunController extends Controller
{
    private DebitOrderRun $runs;
    private DebitOrderRunLine $lines;
    private DebitOrder $debitOrders;
    private Branch $branches;

    public function __construct()
    {
        $this->runs = new DebitOrderRun();
        $this->lines = new DebitOrderRunLine();
        $this->debitOrders = new DebitOrder();
        $this->branches = new Branch();
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

        if (!$branchId) {
            $this->view('debit_order_runs/create', [
                'title' => 'New Debit Order Run',
                'branches' => $this->branches->all(),
                'old' => $_POST,
                'errors' => ['branch_id' => 'Select a branch.'],
            ]);
            return;
        }

        $matches = $this->debitOrders->unregistered($branchId);

        if (empty($matches)) {
            Session::flash('error', 'No active mandates are waiting to be registered with Collexia for that branch.');
            $this->redirect('/debit-order-runs/create');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $totalAmount = array_sum(array_column($matches, 'debit_amount'));

        $runId = $this->runs->create([
            'branch_id' => $branchId,
            'run_no' => generate_reference('DOR'),
            'run_date' => date('Y-m-d'),
            'debit_month' => date('Y-m'),
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
                'bank_reference' => $mandate['merchant_system_contract_no'],
                'status' => 'Pending',
            ]);
        }

        Audit::log('Create', 'Debit Order Runs', 'Generated registration run #' . $runId . ' with ' . count($matches) . ' mandate(s)');
        Session::flash('success', 'Run created with ' . count($matches) . ' mandate(s) to register. Export the EnDo Batch file next.');
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
        ]);
    }

    /**
     * Streams the EnDo Batch workbook Collexia's portal expects for
     * registration -- see CollexiaEndoExporter for the exact layout.
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
        $mandates = [];
        foreach ($lines as $line) {
            $mandate = $this->debitOrders->find((int) $line['debit_order_id']);
            if ($mandate) {
                $mandates[] = $mandate;
            }
        }

        if ($run['status'] === 'Draft') {
            $this->runs->updateRecord($id, [
                'status' => 'Generated',
                'generated_by' => Auth::user()['id'] ?? null,
                'generated_at' => date('Y-m-d H:i:s'),
            ]);
            Audit::log('Generate', 'Debit Order Runs', 'Exported EnDo Batch file for run #' . $id);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $spreadsheet = CollexiaEndoExporter::build($mandates);
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $run['run_no']) . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Marks the run submitted to Collexia's portal AND flips every mandate
     * in it to Registered -- registration is one-time, so these mandates
     * will never be swept into a future run again. Collexia's own
     * "Scheduled Installments" report is later imported separately (see
     * DebitOrderCollectionController) to reconcile actual collections.
     */
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

        foreach ($this->lines->forRun($id) as $line) {
            $this->debitOrders->markRegistered((int) $line['debit_order_id']);
            $this->lines->updateRecord((int) $line['id'], ['status' => 'Successful']);
        }

        $this->runs->updateRecord($id, ['status' => 'Submitted']);
        Audit::log('Submit', 'Debit Order Runs', 'Marked run #' . $id . ' as submitted to Collexia and registered its mandates');
        Session::flash('success', 'Run marked as submitted -- these mandates are now registered with Collexia and will collect automatically.');
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
