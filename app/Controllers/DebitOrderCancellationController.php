<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\DebitOrder;
use App\Models\DebitOrderCancellation;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\DocumentGenerationService;

class DebitOrderCancellationController extends Controller
{
    private DebitOrderCancellation $cancellations;
    private DebitOrder $debitOrders;
    private Borrower $borrowers;
    private DocumentTemplate $templates;
    private GeneratedDocument $documents;

    public function __construct()
    {
        $this->cancellations = new DebitOrderCancellation();
        $this->debitOrders = new DebitOrder();
        $this->borrowers = new Borrower();
        $this->templates = new DocumentTemplate();
        $this->documents = new GeneratedDocument();
    }

    public function index(): void
    {
        Auth::authorize('collections.debit_orders');
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('debit_order_cancellations/index', [
            'title' => 'Debit Order Cancellations',
            'cancellations' => $this->cancellations->paginated($status),
            'status' => $status,
        ]);
    }

    /**
     * $debitOrderId is nullable in the schema so staff can also log a
     * cancellation for a mandate that was never formally captured in this
     * system -- passing "0" here renders a blank borrower/loan-agnostic
     * form instead of a pre-filled one.
     */
    public function create(string $debitOrderId): void
    {
        Auth::authorize('collections.debit_orders');
        $debitOrder = ((int) $debitOrderId) ? $this->debitOrders->find((int) $debitOrderId) : null;

        $this->view('debit_order_cancellations/create', [
            'title' => 'Request Debit Order Cancellation',
            'debitOrder' => $debitOrder,
            'borrowers' => $debitOrder ? [] : $this->borrowers->paginated('', '', 500),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('collections.debit_orders');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-orders');
            return;
        }

        $debitOrderId = (int) ($_POST['debit_order_id'] ?? 0) ?: null;
        $debitOrder = $debitOrderId ? $this->debitOrders->find($debitOrderId) : null;
        $reason = trim($_POST['reason'] ?? '');

        $errors = [];
        if ($reason === '') {
            $errors['reason'] = 'A reason is required.';
        }
        if (!$debitOrder && empty($_POST['borrower_id'])) {
            $errors['borrower_id'] = 'Select a borrower or link an existing debit order.';
        }

        if (!empty($errors)) {
            $this->view('debit_order_cancellations/create', [
                'title' => 'Request Debit Order Cancellation',
                'debitOrder' => $debitOrder,
                'borrowers' => $debitOrder ? [] : $this->borrowers->paginated('', '', 500),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $cancellationId = $this->cancellations->create([
            'borrower_id' => $debitOrder['borrower_id'] ?? (int) $_POST['borrower_id'],
            'loan_id' => $debitOrder['loan_id'] ?? null,
            'debit_order_id' => $debitOrderId,
            'cancellation_no' => generate_reference('DOC-CXL'),
            'cancellation_date' => date('Y-m-d'),
            'amount_cancelled' => $debitOrder['debit_amount'] ?? (float) ($_POST['amount_cancelled'] ?? 0),
            'reason' => $reason,
            'status' => 'Pending',
            'requested_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Debit Order Cancellations', 'Requested cancellation #' . $cancellationId);
        Session::flash('success', 'Cancellation requested. It needs approval to take effect.');
        $this->redirect('/debit-order-cancellations/' . $cancellationId);
    }

    public function show(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $cancellation = $this->cancellations->find((int) $id);

        if (!$cancellation) {
            Session::flash('error', 'Cancellation request not found.');
            $this->redirect('/debit-order-cancellations');
            return;
        }

        $this->view('debit_order_cancellations/show', [
            'title' => 'Cancellation ' . $cancellation['cancellation_no'],
            'cancellation' => $cancellation,
        ]);
    }

    public function approve(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-cancellations/' . $id);
            return;
        }

        $cancellation = $this->cancellations->find($id);
        if (!$cancellation || $cancellation['status'] !== 'Pending') {
            Session::flash('error', 'Only pending cancellations can be approved.');
            $this->redirect('/debit-order-cancellations/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $this->cancellations->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        if (!empty($cancellation['debit_order_id'])) {
            $this->debitOrders->updateRecord((int) $cancellation['debit_order_id'], [
                'status' => 'Cancelled',
                'end_date' => $cancellation['cancellation_date'],
            ]);
        }

        Audit::log('Approve', 'Debit Order Cancellations', 'Approved cancellation #' . $id);
        Session::flash('success', 'Cancellation approved' . (!empty($cancellation['debit_order_id']) ? ' and the debit order mandate marked Cancelled.' : '.'));
        $this->redirect('/debit-order-cancellations/' . $id);
    }

    public function reject(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/debit-order-cancellations/' . $id);
            return;
        }

        $cancellation = $this->cancellations->find($id);
        if (!$cancellation || $cancellation['status'] !== 'Pending') {
            Session::flash('error', 'Only pending cancellations can be rejected.');
            $this->redirect('/debit-order-cancellations/' . $id);
            return;
        }

        $this->cancellations->updateRecord($id, ['status' => 'Rejected']);

        Audit::log('Reject', 'Debit Order Cancellations', 'Rejected cancellation #' . $id);
        Session::flash('success', 'Cancellation request rejected.');
        $this->redirect('/debit-order-cancellations/' . $id);
    }

    public function generateLetter(string $id): void
    {
        Auth::authorize('collections.debit_orders');
        $id = (int) $id;
        $cancellation = $this->cancellations->find($id);

        if (!$cancellation) {
            Session::flash('error', 'Cancellation request not found.');
            $this->redirect('/debit-order-cancellations');
            return;
        }

        $template = $this->templates->findByCode('DEBIT_ORDER_CANCELLATION');
        if (!$template) {
            Session::flash('error', 'The debit order cancellation letter template is not configured yet.');
            $this->redirect('/debit-order-cancellations/' . $id);
            return;
        }

        $documentId = $this->documents->create([
            'template_id' => $template['id'],
            'document_no' => generate_reference('DOC'),
            'document_title' => $template['template_name'] . ' - ' . $cancellation['cancellation_no'],
            'borrower_id' => $cancellation['borrower_id'],
            'loan_id' => $cancellation['loan_id'],
            'debit_order_cancellation_id' => $id,
            'source_module' => 'Debit Order Cancellation',
            'status' => 'Draft',
        ]);

        $document = $this->documents->find($documentId);

        try {
            $filePath = DocumentGenerationService::generate($document);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/debit-order-cancellations/' . $id);
            return;
        }

        $this->documents->markFulfilled($documentId, $filePath, Auth::user()['id'] ?? null);

        $fullPath = STORAGE_PATH . '/' . $filePath;
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
