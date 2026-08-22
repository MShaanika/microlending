<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmDocumentType;
use App\Models\HrmEmployee;
use App\Models\StaffLoan;
use App\Models\StaffLoanDocument;
use App\Models\StaffLoanRepayment;
use App\Models\StaffLoanType;

class StaffLoanController extends Controller
{
    private const STATUSES = ['Pending', 'Active', 'Completed', 'Cancelled', 'Rejected'];
    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024; // 5MB

    private StaffLoan $loans;
    private StaffLoanType $types;
    private HrmEmployee $employees;
    private StaffLoanDocument $documents;
    private HrmDocumentType $documentTypes;

    public function __construct()
    {
        $this->loans = new StaffLoan();
        $this->types = new StaffLoanType();
        $this->employees = new HrmEmployee();
        $this->documents = new StaffLoanDocument();
        $this->documentTypes = new HrmDocumentType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'created_at');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->loans->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Staff Loans',
            'loans' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'employees' => $this->employees->allEmployees(),
            'statuses' => self::STATUSES,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/staff-loans/index', $data);
            return;
        }
        $this->view('hrm/staff-loans/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'New Staff Loan',
            'employees' => $this->employees->allEmployees(),
            'types' => $this->types->allTypes(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/staff-loans/create', $data);
            return;
        }
        $this->view('hrm/staff-loans/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/staff-loans/create', [
                'title' => 'New Staff Loan',
                'employees' => $this->employees->allEmployees(),
                'types' => $this->types->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['installment_amount'] = round($data['principal_amount'] / $data['number_of_installments'], 2);
        $data['outstanding_balance'] = $data['principal_amount'];
        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->loans->create($data);

        Audit::log('Create', 'HRM', 'Staff loan #' . $id . ' created - ' . $data['title']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Staff loan submitted for approval.');
        }
        Session::flash('success', 'Staff loan submitted for approval.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $loan = $this->loans->find($id);
        if (!$loan) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Staff loan not found.'], 404);
            }
            Session::flash('error', 'Staff loan not found.');
            $this->redirect('/hrm/staff-loans');
            return;
        }
        $data = [
            'title' => 'Staff Loan',
            'loan' => $loan,
            'repayments' => (new StaffLoanRepayment())->forLoan($id),
            'documents' => $this->documents->forLoan($id),
            'documentTypes' => $this->documentTypes->allTypes(),
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/staff-loans/show', $data);
            return;
        }
        $this->view('hrm/staff-loans/show', $data);
    }

    public function approve(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || $loan['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending loans can be approved.'], 422);
            }
            Session::flash('error', 'Only pending loans can be approved.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->updateRecord($id, ['status' => 'Active', 'approved_by' => Auth::user()['id'] ?? null]);

        Audit::log('Update', 'HRM', 'Staff loan #' . $id . ' approved');

        if ($this->isAjax()) {
            $this->jsonSuccess('Staff loan approved. It will be deducted starting from its start date.', '/hrm/staff-loans/' . $id);
        }
        Session::flash('success', 'Staff loan approved. It will be deducted starting from its start date.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function reject(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || $loan['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending loans can be rejected.'], 422);
            }
            Session::flash('error', 'Only pending loans can be rejected.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->updateRecord($id, ['status' => 'Rejected', 'approved_by' => Auth::user()['id'] ?? null]);

        Audit::log('Update', 'HRM', 'Staff loan #' . $id . ' rejected');

        if ($this->isAjax()) {
            $this->jsonSuccess('Staff loan rejected.', '/hrm/staff-loans/' . $id);
        }
        Session::flash('success', 'Staff loan rejected.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function cancel(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || !in_array($loan['status'], ['Pending', 'Active'], true)) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending or active loans can be cancelled.'], 422);
            }
            Session::flash('error', 'Only pending or active loans can be cancelled.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->updateRecord($id, ['status' => 'Cancelled']);

        Audit::log('Update', 'HRM', 'Staff loan #' . $id . ' cancelled');

        if ($this->isAjax()) {
            $this->jsonSuccess('Staff loan cancelled. No further deductions will be made.', '/hrm/staff-loans/' . $id);
        }
        Session::flash('success', 'Staff loan cancelled. No further deductions will be made.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan || in_array($loan['status'], ['Active', 'Completed'], true)) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Active or completed loans (with repayment history) cannot be deleted -- cancel instead.'], 422);
            }
            Session::flash('error', 'Active or completed loans (with repayment history) cannot be deleted -- cancel instead.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $this->loans->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted staff loan #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Staff loan deleted.');
        }
        Session::flash('success', 'Staff loan deleted.');
        $this->redirect('/hrm/staff-loans');
    }

    public function uploadDocument(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $loan = $this->loans->find($id);
        if (!$loan) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Staff loan not found.'], 404);
            }
            Session::flash('error', 'Staff loan not found.');
            $this->redirect('/hrm/staff-loans');
            return;
        }

        $file = $_FILES['document'] ?? null;
        $error = $this->validateDocument($file);
        if ($error) {
            if ($this->isAjax()) {
                $this->jsonErrors(['document' => $error]);
            }
            Session::flash('error', $error);
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $this->storeDocument($id, $file, !empty($_POST['document_type_id']) ? (int) $_POST['document_type_id'] : null, Auth::user()['id'] ?? null);

        Audit::log('Create', 'HRM', 'Uploaded document for staff loan #' . $id . ' - ' . $file['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Document uploaded.', '/hrm/staff-loans/' . $id);
        }
        Session::flash('success', 'Document uploaded.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    public function downloadDocument(int $id, int $documentId): void
    {
        Auth::authorize('hrm.view');
        $document = $this->documents->find($documentId);

        if (!$document || (int) $document['staff_loan_id'] !== $id) {
            Session::flash('error', 'Document not found.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $mime = match ($document['file_type']) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($document['document_name']) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    public function deleteDocument(int $id, int $documentId): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $document = $this->documents->find($documentId);
        if (!$document || (int) $document['staff_loan_id'] !== $id) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Document not found.'], 404);
            }
            Session::flash('error', 'Document not found.');
            $this->redirect('/hrm/staff-loans/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
        $this->documents->delete($documentId);

        Audit::log('Delete', 'HRM', 'Deleted document #' . $documentId . ' for staff loan #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Document deleted.', '/hrm/staff-loans/' . $id);
        }
        Session::flash('success', 'Document deleted.');
        $this->redirect('/hrm/staff-loans/' . $id);
    }

    private function validateDocument(?array $file): ?string
    {
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return 'Choose a file to upload.';
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'Upload failed. Please try again.';
        }
        if ($file['size'] > self::MAX_DOCUMENT_SIZE) {
            return 'File is too large (max 5MB).';
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
            return 'Only PDF, JPG and PNG files are allowed.';
        }
        return null;
    }

    private function storeDocument(int $staffLoanId, array $file, ?int $documentTypeId, ?int $userId): void
    {
        $targetDir = STORAGE_PATH . '/uploads/staff_loan_documents/' . $staffLoanId;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $storedName = uniqid('doc_', true) . '.' . $ext;
        $destination = $targetDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return;
        }

        $this->documents->create([
            'staff_loan_id' => $staffLoanId,
            'document_type_id' => $documentTypeId,
            'document_name' => $file['name'],
            'file_path' => 'uploads/staff_loan_documents/' . $staffLoanId . '/' . $storedName,
            'file_type' => $ext,
            'file_size' => $file['size'],
            'uploaded_by' => $userId,
        ]);
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $title = trim($post['title'] ?? '');
        $principalAmount = isset($post['principal_amount']) && $post['principal_amount'] !== '' ? (float) $post['principal_amount'] : null;
        $numberOfInstallments = isset($post['number_of_installments']) && $post['number_of_installments'] !== '' ? (int) $post['number_of_installments'] : null;
        $startDate = trim($post['start_date'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($principalAmount === null || $principalAmount <= 0) {
            $errors['principal_amount'] = 'Enter a principal amount greater than 0.';
        }
        if ($numberOfInstallments === null || $numberOfInstallments < 1) {
            $errors['number_of_installments'] = 'Enter at least 1 installment.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'staff_loan_type_id' => !empty($post['staff_loan_type_id']) ? (int) $post['staff_loan_type_id'] : null,
            'title' => $title,
            'principal_amount' => $principalAmount ?? 0,
            'number_of_installments' => $numberOfInstallments ?? 1,
            'start_date' => $startDate ?: null,
            'reason' => trim($post['reason'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
