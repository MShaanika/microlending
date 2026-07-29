<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\HrmDepartment;
use App\Models\HrmDesignation;
use App\Models\HrmDocumentType;
use App\Models\HrmEmployee;
use App\Models\HrmEmployeeDocument;
use App\Models\HrmShift;
use App\Models\User;

class HrmEmployeeController extends Controller
{
    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024; // 5MB

    private HrmEmployee $employees;
    private Branch $branches;
    private HrmDepartment $departments;
    private HrmDesignation $designations;
    private HrmShift $shifts;
    private User $users;
    private HrmDocumentType $documentTypes;
    private HrmEmployeeDocument $documents;

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->branches = new Branch();
        $this->departments = new HrmDepartment();
        $this->designations = new HrmDesignation();
        $this->shifts = new HrmShift();
        $this->users = new User();
        $this->documentTypes = new HrmDocumentType();
        $this->documents = new HrmEmployeeDocument();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'branch_id' => $_GET['branch_id'] ?? '',
            'department_id' => $_GET['department_id'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => trim($_GET['search'] ?? ''),
        ];

        $this->view('hrm/employees/index', [
            'title' => 'Employees',
            'employees' => $this->employees->allEmployees($filters),
            'counts' => $this->employees->counts(),
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/employees/create', array_merge($this->formData(), [
            'title' => 'Add Employee',
            'old' => [],
            'errors' => [],
        ]));
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employees/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/employees/create', array_merge($this->formData(), [
                'title' => 'Add Employee',
                'old' => $_POST,
                'errors' => $errors,
            ]));
            return;
        }

        $data['employee_no'] = generate_reference('EMP');
        while ($this->employees->employeeNoExists($data['employee_no'])) {
            $data['employee_no'] = generate_reference('EMP');
        }
        $data['created_by'] = Auth::user()['id'] ?? null;

        $id = $this->employees->create($data);

        Audit::log('Create', 'HRM', 'Created employee #' . $id . ' - ' . $data['first_name'] . ' ' . $data['last_name']);
        Session::flash('success', 'Employee added.');
        $this->redirect('/hrm/employees/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $employee = $this->employees->find($id);
        if (!$employee) {
            Session::flash('error', 'Employee not found.');
            $this->redirect('/hrm/employees');
            return;
        }
        $this->view('hrm/employees/show', [
            'title' => $employee['first_name'] . ' ' . $employee['last_name'],
            'employee' => $employee,
            'documents' => $this->documents->forEmployee($id),
            'documentTypes' => $this->documentTypes->allTypes(),
        ]);
    }

    public function uploadDocument(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employees/' . $id);
            return;
        }

        $employee = $this->employees->find($id);
        if (!$employee) {
            Session::flash('error', 'Employee not found.');
            $this->redirect('/hrm/employees');
            return;
        }

        $file = $_FILES['document'] ?? null;
        $error = $this->validateDocument($file);
        if ($error) {
            Session::flash('error', $error);
            $this->redirect('/hrm/employees/' . $id);
            return;
        }

        $this->storeDocument($id, $employee['employee_no'], $file, !empty($_POST['document_type_id']) ? (int) $_POST['document_type_id'] : null, Auth::user()['id'] ?? null);

        Audit::log('Create', 'HRM', 'Uploaded document for employee #' . $id . ' - ' . $file['name']);
        Session::flash('success', 'Document uploaded.');
        $this->redirect('/hrm/employees/' . $id);
    }

    public function downloadDocument(int $id, int $documentId): void
    {
        Auth::authorize('hrm.view');
        $document = $this->documents->find($documentId);

        if (!$document || (int) $document['employee_id'] !== $id) {
            Session::flash('error', 'Document not found.');
            $this->redirect('/hrm/employees/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/hrm/employees/' . $id);
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
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employees/' . $id);
            return;
        }

        $document = $this->documents->find($documentId);
        if (!$document || (int) $document['employee_id'] !== $id) {
            Session::flash('error', 'Document not found.');
            $this->redirect('/hrm/employees/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (is_file($fullPath)) {
            unlink($fullPath);
        }
        $this->documents->delete($documentId);

        Audit::log('Delete', 'HRM', 'Deleted document #' . $documentId . ' for employee #' . $id);
        Session::flash('success', 'Document deleted.');
        $this->redirect('/hrm/employees/' . $id);
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

    private function storeDocument(int $employeeId, string $employeeNo, array $file, ?int $documentTypeId, ?int $userId): void
    {
        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $employeeNo);
        $targetDir = STORAGE_PATH . '/uploads/employee_documents/' . $safeFolder;
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
            'employee_id' => $employeeId,
            'document_type_id' => $documentTypeId,
            'document_name' => $file['name'],
            'file_path' => 'uploads/employee_documents/' . $safeFolder . '/' . $storedName,
            'file_type' => $ext,
            'file_size' => $file['size'],
            'uploaded_by' => $userId,
        ]);
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $employee = $this->employees->find($id);
        if (!$employee) {
            Session::flash('error', 'Employee not found.');
            $this->redirect('/hrm/employees');
            return;
        }
        $this->view('hrm/employees/edit', array_merge($this->formData(), [
            'title' => 'Edit Employee',
            'employee' => $employee,
            'errors' => [],
        ]));
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/employees/' . $id . '/edit');
            return;
        }

        $employee = $this->employees->find($id);
        if (!$employee) {
            Session::flash('error', 'Employee not found.');
            $this->redirect('/hrm/employees');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/employees/edit', array_merge($this->formData(), [
                'title' => 'Edit Employee',
                'employee' => array_merge($employee, $_POST),
                'errors' => $errors,
            ]));
            return;
        }

        $this->employees->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated employee #' . $id . ' - ' . $data['first_name'] . ' ' . $data['last_name']);
        Session::flash('success', 'Employee updated.');
        $this->redirect('/hrm/employees/' . $id);
    }

    private function formData(): array
    {
        return [
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'designations' => $this->designations->allDesignations(true),
            'shifts' => $this->shifts->allShifts(true),
            'availableUsers' => $this->users->paginated('', 'active'),
        ];
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $errors = [];

        $firstName = trim($post['first_name'] ?? '');
        $lastName = trim($post['last_name'] ?? '');
        if ($firstName === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        $email = trim($post['email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $userId = !empty($post['user_id']) ? (int) $post['user_id'] : null;
        if ($userId !== null && $this->employees->userIdInUse($userId, $excludeId)) {
            $errors['user_id'] = 'That system user is already linked to another employee.';
        }

        $data = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email ?: null,
            'phone' => trim($post['phone'] ?? '') ?: null,
            'date_of_birth' => trim($post['date_of_birth'] ?? '') ?: null,
            'gender' => in_array($post['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $post['gender'] : 'Male',
            'date_of_joining' => trim($post['date_of_joining'] ?? '') ?: null,
            'employment_type' => in_array($post['employment_type'] ?? '', ['Full-Time', 'Part-Time', 'Contract', 'Intern'], true)
                ? $post['employment_type'] : 'Full-Time',
            'status' => in_array($post['status'] ?? '', ['Active', 'On Leave', 'Suspended', 'Terminated'], true)
                ? $post['status'] : 'Active',
            'address_line_1' => trim($post['address_line_1'] ?? '') ?: null,
            'address_line_2' => trim($post['address_line_2'] ?? '') ?: null,
            'city' => trim($post['city'] ?? '') ?: null,
            'region' => trim($post['region'] ?? '') ?: null,
            'country' => trim($post['country'] ?? '') ?: 'Namibia',
            'postal_code' => trim($post['postal_code'] ?? '') ?: null,
            'emergency_contact_name' => trim($post['emergency_contact_name'] ?? '') ?: null,
            'emergency_contact_relationship' => trim($post['emergency_contact_relationship'] ?? '') ?: null,
            'emergency_contact_number' => trim($post['emergency_contact_number'] ?? '') ?: null,
            'bank_name' => trim($post['bank_name'] ?? '') ?: null,
            'account_holder_name' => trim($post['account_holder_name'] ?? '') ?: null,
            'account_number' => trim($post['account_number'] ?? '') ?: null,
            'branch_code' => trim($post['branch_code'] ?? '') ?: null,
            'tax_payer_id' => trim($post['tax_payer_id'] ?? '') ?: null,
            'basic_salary' => $post['basic_salary'] !== '' && isset($post['basic_salary']) ? (float) $post['basic_salary'] : null,
            'rate_per_hour' => $post['rate_per_hour'] !== '' && isset($post['rate_per_hour']) ? (float) $post['rate_per_hour'] : null,
            'hours_per_day' => $post['hours_per_day'] !== '' && isset($post['hours_per_day']) ? (float) $post['hours_per_day'] : 8,
            'days_per_week' => $post['days_per_week'] !== '' && isset($post['days_per_week']) ? (float) $post['days_per_week'] : 5,
            'user_id' => $userId,
            'branch_id' => !empty($post['branch_id']) ? (int) $post['branch_id'] : null,
            'department_id' => !empty($post['department_id']) ? (int) $post['department_id'] : null,
            'designation_id' => !empty($post['designation_id']) ? (int) $post['designation_id'] : null,
            'shift_id' => !empty($post['shift_id']) ? (int) $post['shift_id'] : null,
        ];

        return [$data, $errors];
    }
}
