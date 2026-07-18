<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Loan;
use App\Models\LoanReschedule;
use App\Models\LoanRescheduleSchedule;
use App\Services\DocumentGenerationService;
use App\Services\RescheduleService;

class RescheduleController extends Controller
{
    private LoanReschedule $reschedules;
    private LoanRescheduleSchedule $rescheduleSchedules;
    private Loan $loans;
    private DocumentTemplate $templates;
    private GeneratedDocument $documents;

    public function __construct()
    {
        $this->reschedules = new LoanReschedule();
        $this->rescheduleSchedules = new LoanRescheduleSchedule();
        $this->loans = new Loan();
        $this->templates = new DocumentTemplate();
        $this->documents = new GeneratedDocument();
    }

    public function index(): void
    {
        Auth::authorize('reschedules.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $this->view('reschedules/index', [
            'title' => 'Loan Reschedules',
            'reschedules' => $this->reschedules->paginated($status),
            'status' => $status,
        ]);
    }

    public function create(string $loanId): void
    {
        Auth::authorize('reschedules.create');
        $loan = $this->loans->find((int) $loanId);

        if (!$loan || !in_array($loan['loan_status'], ['Active', 'Current'], true)) {
            Session::flash('error', 'Only active loans can be rescheduled.');
            $this->redirect('/loans/' . $loanId);
            return;
        }

        $this->view('reschedules/create', [
            'title' => 'Reschedule ' . $loan['loan_no'],
            'loan' => $loan,
            'outstandingBalance' => RescheduleService::outstandingPrincipal((int) $loan['id']),
            'preview' => null,
            'old' => [],
            'errors' => [],
        ]);
    }

    /**
     * Same form re-posts here to show a live preview before the actual
     * request is saved -- this app doesn't lean on JS/AJAX for this kind of
     * calculation elsewhere (see LoanController's plan dropdown being the
     * exception, not the rule), so a plain server round-trip matches
     * existing convention.
     */
    public function previewAction(string $loanId): void
    {
        Auth::authorize('reschedules.create');
        $loan = $this->loans->find((int) $loanId);

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }

        $termMonths = max(1, (int) ($_POST['new_term_months'] ?? $loan['term_months']));
        $waived = (float) ($_POST['waived_amount'] ?? 0);
        $effectiveDate = $_POST['effective_date'] ?: date('Y-m-d');
        // Blank "New Payment Day" means "no change," not "clear it."
        $paymentDay = ($_POST['new_payment_day'] ?? '') !== '' ? (int) $_POST['new_payment_day'] : (int) $loan['payment_day'];

        $preview = RescheduleService::preview($loan, $loan['interest_method'], $termMonths, $waived, $effectiveDate, $paymentDay);

        $this->view('reschedules/create', [
            'title' => 'Reschedule ' . $loan['loan_no'],
            'loan' => $loan,
            'outstandingBalance' => $preview['outstanding_balance'],
            'preview' => $preview,
            'old' => $_POST,
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('reschedules.create');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/loans');
            return;
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->find($loanId);
        $reason = trim($_POST['reason'] ?? '');

        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/loans');
            return;
        }

        $termMonths = max(1, (int) ($_POST['new_term_months'] ?? 0));
        // Blank "New Payment Day" means "no change," not "clear it."
        $paymentDay = ($_POST['new_payment_day'] ?? '') !== '' ? (int) $_POST['new_payment_day'] : (int) $loan['payment_day'];
        $waived = (float) ($_POST['waived_amount'] ?? 0);
        $effectiveDate = $_POST['effective_date'] ?: date('Y-m-d');

        if ($reason === '') {
            $preview = RescheduleService::preview($loan, $loan['interest_method'], $termMonths, $waived, $effectiveDate, $paymentDay);
            $this->view('reschedules/create', [
                'title' => 'Reschedule ' . $loan['loan_no'],
                'loan' => $loan,
                'outstandingBalance' => $preview['outstanding_balance'],
                'preview' => $preview,
                'old' => $_POST,
                'errors' => ['reason' => 'A reason is required to request a reschedule.'],
            ]);
            return;
        }

        $preview = RescheduleService::preview($loan, $loan['interest_method'], $termMonths, $waived, $effectiveDate, $paymentDay);
        $userId = Auth::user()['id'] ?? null;

        $rescheduleId = $this->reschedules->create([
            'loan_id' => $loanId,
            'borrower_id' => $loan['borrower_id'],
            'branch_id' => $loan['branch_id'],
            'reschedule_no' => generate_reference('RSC'),
            'request_date' => date('Y-m-d'),
            'effective_date' => $effectiveDate,
            'old_installment_amount' => $loan['installment_amount'],
            'new_installment_amount' => $preview['new_installment_amount'],
            'old_term_months' => $loan['term_months'],
            'new_term_months' => $termMonths,
            'old_payment_day' => $loan['payment_day'],
            'new_payment_day' => $paymentDay,
            'old_maturity_date' => $loan['maturity_date'],
            'new_maturity_date' => $preview['new_maturity_date'],
            'outstanding_balance' => $preview['outstanding_balance'],
            'interest_adjustment' => 0,
            'fee_adjustment' => 0,
            'waived_amount' => $waived,
            'reason' => $reason,
            'status' => 'Pending',
            'requested_by' => $userId,
        ]);

        foreach ($preview['rows'] as $row) {
            $this->rescheduleSchedules->create([
                'reschedule_id' => $rescheduleId,
                'loan_id' => $loanId,
                'installment_no' => $row['installment_no'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'fees_due' => $row['fees_due'],
                'namfisa_levy_due' => $row['namfisa_levy_due'] ?? 0,
                'duty_stamp_due' => $row['duty_stamp_due'] ?? 0,
                'penalty_due' => 0,
                'total_due' => $row['total_due'],
                'status' => 'Pending',
            ]);
        }

        Audit::log('Create', 'Reschedules', 'Requested reschedule #' . $rescheduleId . ' for loan ' . $loan['loan_no']);
        Session::flash('success', 'Reschedule requested. It needs approval before it can be implemented.');
        $this->redirect('/reschedules/' . $rescheduleId);
    }

    public function show(string $id): void
    {
        Auth::authorize('reschedules.view');
        $reschedule = $this->reschedules->find((int) $id);

        if (!$reschedule) {
            Session::flash('error', 'Reschedule not found.');
            $this->redirect('/reschedules');
            return;
        }

        $loan = $this->loans->find((int) $reschedule['loan_id']);

        // Once Implemented, the new schedule lives on (and is paid down via)
        // the loan's own loan_schedules -- showing that instead of the frozen
        // loan_reschedule_schedules preview means Paid/Arrears here reflect
        // reality. Before that, there's nothing live yet, so the frozen
        // preview (with Paid/Arrears both 0) is all there is to show.
        $isLive = $reschedule['status'] === 'Implemented';
        $newSchedule = $isLive
            ? $this->loans->schedule((int) $reschedule['loan_id'])
            : $this->rescheduleSchedules->forReschedule((int) $id);

        $today = date('Y-m-d');
        foreach ($newSchedule as &$row) {
            $paid = (float) ($row['total_paid'] ?? 0);
            $due = (float) $row['total_due'];
            $row['paid_amount'] = $paid;
            $row['arrears'] = ($row['due_date'] < $today && $due > $paid) ? round($due - $paid, 2) : 0.0;
        }
        unset($row);

        $totalPayable = array_sum(array_column($newSchedule, 'total_due'));
        $collectedSoFar = array_sum(array_column($newSchedule, 'paid_amount'));

        $this->view('reschedules/show', [
            'title' => 'Reschedule ' . $reschedule['reschedule_no'],
            'reschedule' => $reschedule,
            'loan' => $loan,
            'newSchedule' => $newSchedule,
            'isLive' => $isLive,
            // Amount Borrowed / Principal Amount / Interest Charge / Opening
            // Balance -- derived from the actual persisted new schedule
            // rows rather than stored separately, so they can never drift
            // from what's really there. See RescheduleService::preview().
            'principalAmount' => array_sum(array_column($newSchedule, 'principal_due')),
            'interestCharge' => array_sum(array_column($newSchedule, 'interest_due')),
            'openingBalance' => $totalPayable,
            'totalPayable' => $totalPayable,
            'collectedSoFar' => $collectedSoFar,
            'currentOutstandingBalance' => round($totalPayable - $collectedSoFar, 2),
        ]);
    }

    public function approve(string $id): void
    {
        Auth::authorize('reschedules.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $reschedule = $this->reschedules->find($id);
        if (!$reschedule || $reschedule['status'] !== 'Pending') {
            Session::flash('error', 'Only pending reschedules can be approved.');
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $this->reschedules->updateRecord($id, [
            'status' => 'Approved',
            'reviewed_by' => Auth::user()['id'] ?? null,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'approved_by' => Auth::user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Approve', 'Reschedules', 'Approved reschedule #' . $id);
        Session::flash('success', 'Reschedule approved. It can now be implemented.');
        $this->redirect('/reschedules/' . $id);
    }

    public function reject(string $id): void
    {
        Auth::authorize('reschedules.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $reschedule = $this->reschedules->find($id);
        if (!$reschedule || $reschedule['status'] !== 'Pending') {
            Session::flash('error', 'Only pending reschedules can be rejected.');
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $reason = trim($_POST['rejection_reason'] ?? '');
        $this->reschedules->updateRecord($id, [
            'status' => 'Rejected',
            'reviewed_by' => Auth::user()['id'] ?? null,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason ?: null,
        ]);

        Audit::log('Reject', 'Reschedules', 'Rejected reschedule #' . $id);
        Session::flash('success', 'Reschedule rejected.');
        $this->redirect('/reschedules/' . $id);
    }

    public function implement(string $id): void
    {
        Auth::authorize('reschedules.implement');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $reschedule = $this->reschedules->find($id);
        if (!$reschedule || $reschedule['status'] !== 'Approved') {
            Session::flash('error', 'Only approved reschedules can be implemented.');
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $newRows = $this->rescheduleSchedules->forReschedule($id);
        $userId = Auth::user()['id'] ?? null;

        try {
            RescheduleService::implement($reschedule, $newRows, $userId);
        } catch (\Throwable $e) {
            Session::flash('error', 'Could not implement reschedule: ' . $e->getMessage());
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $this->reschedules->updateRecord($id, [
            'status' => 'Implemented',
            'implemented_by' => $userId,
            'implemented_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Implement', 'Reschedules', 'Implemented reschedule #' . $id . ' for loan ' . $reschedule['loan_no']);
        Session::flash('success', 'Reschedule implemented. The loan now follows its new schedule.');
        $this->redirect('/reschedules/' . $id);
    }

    public function generateLetter(string $id): void
    {
        Auth::authorize('reschedules.view');
        $id = (int) $id;
        $reschedule = $this->reschedules->find($id);

        if (!$reschedule) {
            Session::flash('error', 'Reschedule not found.');
            $this->redirect('/reschedules');
            return;
        }

        $template = $this->templates->findByCode('LOAN_RESCHEDULE_LETTER');
        if (!$template) {
            Session::flash('error', 'The loan reschedule letter template is not configured yet.');
            $this->redirect('/reschedules/' . $id);
            return;
        }

        $documentId = $this->documents->create([
            'template_id' => $template['id'],
            'document_no' => generate_reference('DOC'),
            'document_title' => $template['template_name'] . ' - ' . $reschedule['reschedule_no'],
            'borrower_id' => $reschedule['borrower_id'],
            'loan_id' => $reschedule['loan_id'],
            'reschedule_id' => $id,
            'source_module' => 'Reschedule',
            'status' => 'Draft',
        ]);

        $document = $this->documents->find($documentId);

        try {
            $filePath = DocumentGenerationService::generate($document);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/reschedules/' . $id);
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
