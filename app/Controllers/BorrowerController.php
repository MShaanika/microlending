<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\BorrowerAffordability;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PortalUser;
use App\Models\UploadRequirement;
use App\Services\EmailSenderService;
use App\Services\SmsSenderService;

class BorrowerController extends Controller
{
    private Borrower $borrowers;
    private Branch $branches;
    private UploadRequirement $uploadRequirements;
    private PortalUser $portalUsers;
    private BorrowerAffordability $affordability;

    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024; // 5MB

    public function __construct()
    {
        $this->borrowers = new Borrower();
        $this->portalUsers = new PortalUser();
        $this->branches = new Branch();
        $this->uploadRequirements = new UploadRequirement();
        $this->affordability = new BorrowerAffordability();
    }

    public function index(): void
    {
        Auth::authorize('borrowers.view');

        $search = trim((string) ($_GET['q'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $branchId = $this->indexBranchId();

        $this->view('borrowers/index', [
            'title' => 'Borrowers',
            'borrowers' => $this->borrowers->paginated($search, $status, 50, $branchId),
            'search' => $search,
            'status' => $status,
            'branches' => Auth::isSuperAdmin() ? $this->branches->all() : [],
            'selectedBranchId' => $branchId,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('borrowers.create');
        $scopeBranchId = $this->scopeBranchId();
        $this->view('borrowers/create', [
            'title' => 'Add Borrower',
            'branches' => $scopeBranchId === null ? $this->branches->all() : array_values(array_filter($this->branches->all(), fn($b) => (int) $b['id'] === $scopeBranchId)),
            'documentRequirements' => $this->uploadRequirements->forBorrowers(),
            'existingBorrowers' => $this->borrowers->paginated('', '', 500, $scopeBranchId),
            'old' => [],
            'errors' => [],
        ]);
    }

    /** Hard scope for create/store/show/edit/update/delete -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        // 0 for a non-Super-Admin with no branch assigned -- never null,
        // since null means "unscoped" to the model layer and no real
        // branch has id 0, a misconfigured account sees nothing rather
        // than silently falling through to see every branch's data.
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow the list via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    /** Redirects away (404-style) if the record belongs to another branch and the viewer isn't Super Admin. */
    private function assertBranchAccess(?array $record): void
    {
        if (!$record || Auth::isSuperAdmin()) {
            return;
        }
        if ((int) ($record['branch_id'] ?? 0) !== (int) Auth::branchId()) {
            Session::flash('error', 'Borrower not found.');
            $this->redirect('/borrowers');
        }
    }

    public function store(): void
    {
        Auth::authorize('borrowers.create');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/borrowers/create');
        }

        if (($_POST['client_mode'] ?? 'new') === 'existing') {
            $this->storeForExistingBorrower();
            return;
        }

        $scopeBranchId = $this->scopeBranchId();
        // A non-Super-Admin's branch is fixed server-side -- never trust a
        // posted branch_id for them, so a tampered form field can't create
        // a borrower under a branch they don't belong to.
        if ($scopeBranchId !== null) {
            $_POST['branch_id'] = $scopeBranchId;
        }

        $errors = $this->validate($_POST);

        if (!empty($_POST['id_number']) && $this->borrowers->idNumberExists(trim($_POST['id_number']))) {
            $errors['id_number'] = 'A borrower with this ID number already exists.';
        }

        $documentErrors = $this->validateDocumentUploads($_FILES['documents'] ?? []);
        $errors = array_merge($errors, $documentErrors, $this->validateBankStatementUpload($_POST, $_FILES));

        if (!empty($errors)) {
            $this->view('borrowers/create', [
                'title' => 'Add Borrower',
                'branches' => $scopeBranchId === null ? $this->branches->all() : array_values(array_filter($this->branches->all(), fn($b) => (int) $b['id'] === $scopeBranchId)),
                'documentRequirements' => $this->uploadRequirements->forBorrowers(),
                'existingBorrowers' => $this->borrowers->paginated('', '', 500, $scopeBranchId),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $borrowerNo = generate_reference('BRW');

        $borrowerData = [
            'branch_id' => (int) $_POST['branch_id'],
            'borrower_no' => $borrowerNo,
            'first_name' => trim($_POST['first_name']),
            'middle_name' => trim($_POST['middle_name'] ?? '') ?: null,
            'last_name' => trim($_POST['last_name']),
            'gender' => $_POST['gender'] ?: null,
            'date_of_birth' => $_POST['date_of_birth'] ?: null,
            'id_number' => trim($_POST['id_number'] ?? '') ?: null,
            'passport_no' => trim($_POST['passport_no'] ?? '') ?: null,
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'physical_address' => trim($_POST['physical_address'] ?? '') ?: null,
            'postal_address' => trim($_POST['postal_address'] ?? '') ?: null,
            'marital_status' => $_POST['marital_status'] ?: null,
            'nationality' => trim($_POST['nationality'] ?? '') ?: 'Namibian',
            'status' => 'Pending',
            'created_by' => $userId,
        ];

        $bankData = $this->collectBankDetails($_POST);
        $employmentData = $this->collectEmployment($_POST);
        $contactsData = $this->collectContacts($_POST);

        $id = $this->borrowers->createFull($borrowerData, $bankData, $employmentData, $contactsData);

        $this->storeDocumentUploads($id, $borrowerNo, $_FILES['documents'] ?? [], $userId);
        $this->storeBankStatementUploads($id, $borrowerNo, $_POST, $_FILES, $userId);

        $affordabilityData = $this->collectAffordability($_POST);
        if ($affordabilityData) {
            $affordabilityData['borrower_id'] = $id;
            $affordabilityData['recorded_by'] = $userId;
            $this->affordability->create($affordabilityData);
        }

        Audit::log('Create', 'Borrowers', 'Created borrower #' . $id . ' with full profile (bank/employment/contacts/documents).');
        Session::flash('success', 'Borrower registered successfully.');
        $this->redirect('/borrowers/' . $id);
    }

    /**
     * "Existing client" path: staff select an already-registered borrower
     * and just upload documents (and optionally refresh their affordability
     * worksheet) -- no need to re-enter personal/employment/banking details.
     */
    private function storeForExistingBorrower(): void
    {
        $borrowerId = (int) ($_POST['existing_borrower_id'] ?? 0);
        $borrower = $borrowerId ? $this->borrowers->find($borrowerId) : null;

        if (!$borrower) {
            Session::flash('error', 'Select an existing borrower.');
            $this->redirect('/borrowers/create');
            return;
        }
        $this->assertBranchAccess($borrower);

        $documentErrors = array_merge(
            $this->validateDocumentUploads($_FILES['documents'] ?? []),
            $this->validateBankStatementUpload($_POST, $_FILES)
        );

        if (!empty($documentErrors)) {
            $scopeBranchId = $this->scopeBranchId();
            $this->view('borrowers/create', [
                'title' => 'Add Borrower',
                'branches' => $scopeBranchId === null ? $this->branches->all() : array_values(array_filter($this->branches->all(), fn($b) => (int) $b['id'] === $scopeBranchId)),
                'documentRequirements' => $this->uploadRequirements->forBorrowers(),
                'existingBorrowers' => $this->borrowers->paginated('', '', 500, $scopeBranchId),
                'old' => array_merge($_POST, ['client_mode' => 'existing']),
                'errors' => $documentErrors,
            ]);
            return;
        }

        $userId = Auth::user()['id'] ?? null;

        $this->storeDocumentUploads($borrowerId, $borrower['borrower_no'], $_FILES['documents'] ?? [], $userId);
        $this->storeBankStatementUploads($borrowerId, $borrower['borrower_no'], $_POST, $_FILES, $userId);

        $affordabilityData = $this->collectAffordability($_POST);
        if ($affordabilityData) {
            $affordabilityData['borrower_id'] = $borrowerId;
            $affordabilityData['recorded_by'] = $userId;
            $this->affordability->create($affordabilityData);
        }

        Audit::log('Update', 'Borrowers', 'Uploaded documents for existing borrower #' . $borrowerId . '.');
        Session::flash('success', 'Documents uploaded for ' . $borrower['first_name'] . ' ' . $borrower['last_name'] . '.');
        $this->redirect('/borrowers/' . $borrowerId);
    }

    private function collectBankDetails(array $post): ?array
    {
        $bankName = trim($post['bank_name'] ?? '');
        $accountNumber = trim($post['account_number'] ?? '');

        if ($bankName === '' && $accountNumber === '') {
            return null;
        }

        return [
            'bank_name' => $bankName ?: null,
            'account_name' => trim($post['account_name'] ?? '') ?: null,
            'account_number' => $accountNumber ?: null,
            'account_type' => $post['account_type'] ?: null,
            'branch_name' => trim($post['bank_branch_name'] ?? '') ?: null,
            'branch_code' => trim($post['bank_branch_code'] ?? '') ?: null,
            'is_primary' => 1,
        ];
    }

    private function collectEmployment(array $post): ?array
    {
        $employerName = trim($post['employer_name'] ?? '');
        if ($employerName === '') {
            return null;
        }

        return [
            'employer_name' => $employerName,
            'employee_no' => trim($post['employee_no'] ?? '') ?: null,
            'job_title' => trim($post['job_title'] ?? '') ?: null,
            'employment_type' => $post['employment_type'] ?: null,
            'employment_start_date' => $post['employment_start_date'] ?: null,
            'gross_salary' => $post['gross_salary'] !== '' ? (float) $post['gross_salary'] : 0,
            'net_salary' => $post['net_salary'] !== '' ? (float) $post['net_salary'] : 0,
            'payment_day' => $post['employment_payment_day'] !== '' ? (int) $post['employment_payment_day'] : null,
            'employer_phone' => trim($post['employer_phone'] ?? '') ?: null,
            'employer_email' => trim($post['employer_email'] ?? '') ?: null,
            'employer_address' => trim($post['employer_address'] ?? '') ?: null,
            'is_current' => 1,
        ];
    }

    /**
     * Other income streams, living expenses, and existing contractual
     * payments -- the same affordability worksheet the public application
     * form collects. Returns null if staff left the whole section blank
     * (it's optional; not every borrower needs a worksheet on file).
     */
    private function collectAffordability(array $post): ?array
    {
        $fields = [
            'commission', 'pension', 'business_income',
            'groceries', 'school_fees', 'transport',
            'home_loan', 'home_rental', 'credit_card', 'personal_loans',
            'education_loan', 'insurance', 'car_payments', 'cell_phone', 'other_credit',
        ];

        $values = [];
        $anyProvided = false;
        foreach ($fields as $field) {
            $raw = trim((string) ($post['aff_' . $field] ?? ''));
            if ($raw !== '') {
                $anyProvided = true;
            }
            $values[$field] = $raw !== '' ? (float) $raw : 0;
        }

        if (!$anyProvided) {
            return null;
        }

        $incomeFields = ['commission', 'pension', 'business_income'];
        $expenseFields = ['groceries', 'school_fees', 'transport'];
        $installmentFields = ['home_loan', 'home_rental', 'credit_card', 'personal_loans', 'education_loan', 'insurance', 'car_payments', 'cell_phone', 'other_credit'];

        $values['total_income'] = array_sum(array_intersect_key($values, array_flip($incomeFields)));
        $values['total_expenses'] = array_sum(array_intersect_key($values, array_flip($expenseFields)));
        $values['total_installments'] = array_sum(array_intersect_key($values, array_flip($installmentFields)));

        return $values;
    }

    /**
     * Mirrors the public application form's bank statement step: staff pick
     * merged (one PDF) or separate (up to 3 files), matching what the
     * borrower would have chosen if they'd applied online themselves.
     */
    private function validateBankStatementUpload(array $post, array $files): array
    {
        $type = $post['bank_statement_type'] ?? '';
        if ($type === '') {
            return [];
        }

        $errors = [];
        if ($type === 'merged') {
            $file = $files['bank_statement_merged'] ?? null;
            if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                $errors = array_merge($errors, $this->validateSingleFile($file, 'bank_statement_merged'));
            }
        } elseif ($type === 'separate') {
            foreach (['bank_statement_1', 'bank_statement_2', 'bank_statement_3'] as $field) {
                $file = $files[$field] ?? null;
                if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    $errors = array_merge($errors, $this->validateSingleFile($file, $field));
                }
            }
        }

        return $errors;
    }

    private function validateSingleFile(array $file, string $fieldKey): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [$fieldKey => 'Upload failed. Please try again.'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['size'] > self::MAX_DOCUMENT_SIZE) {
            return [$fieldKey => 'File is too large (max 5MB).'];
        }
        if (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
            return [$fieldKey => 'Only PDF, JPG and PNG files are allowed.'];
        }

        return [];
    }

    private function storeBankStatementUploads(int $borrowerId, string $borrowerNo, array $post, array $files, ?int $userId): void
    {
        $type = $post['bank_statement_type'] ?? '';
        if ($type === '') {
            return;
        }

        $fieldNames = $type === 'merged'
            ? ['bank_statement_merged']
            : ['bank_statement_1', 'bank_statement_2', 'bank_statement_3'];

        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $borrowerNo);
        $targetDir = STORAGE_PATH . '/uploads/borrowers/' . $safeFolder;

        foreach ($fieldNames as $field) {
            $file = $files[$field] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedName = uniqid('bankstmt_', true) . '.' . $ext;
            $destination = $targetDir . '/' . $storedName;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                continue;
            }

            $this->borrowers->addDocument([
                'borrower_id' => $borrowerId,
                'document_type' => 'Bank Statement',
                'document_name' => 'Bank Statement (' . ($type === 'merged' ? 'Merged' : 'Separate') . ')',
                'file_path' => 'uploads/borrowers/' . $safeFolder . '/' . $storedName,
                'uploaded_by' => $userId,
                'status' => 'Pending',
            ]);
        }
    }

    private function collectContacts(array $post): array
    {
        $contacts = [];
        $rows = $post['contacts'] ?? [];

        foreach ($rows as $row) {
            $fullName = trim($row['full_name'] ?? '');
            if ($fullName === '') {
                continue;
            }

            $contacts[] = [
                'contact_type' => $row['contact_type'] ?: 'Next of Kin',
                'full_name' => $fullName,
                'relationship' => trim($row['relationship'] ?? '') ?: null,
                'phone' => trim($row['phone'] ?? '') ?: null,
                'email' => trim($row['email'] ?? '') ?: null,
                'address' => trim($row['address'] ?? '') ?: null,
            ];
        }

        return $contacts;
    }

    /**
     * Validate any uploaded documents before we touch the database. Returns
     * a map of `documents.{requirementId}` => error message.
     */
    private function validateDocumentUploads(array $files): array
    {
        $errors = [];

        foreach ($files['error'] ?? [] as $requirementId => $error) {
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors["documents.$requirementId"] = 'Upload failed. Please try again.';
                continue;
            }

            $size = $files['size'][$requirementId] ?? 0;
            $name = $files['name'][$requirementId] ?? '';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($size > self::MAX_DOCUMENT_SIZE) {
                $errors["documents.$requirementId"] = 'File is too large (max 5MB).';
            } elseif (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
                $errors["documents.$requirementId"] = 'Only PDF, JPG and PNG files are allowed.';
            }
        }

        return $errors;
    }

    private function storeDocumentUploads(int $borrowerId, string $borrowerNo, array $files, ?int $userId): void
    {
        if (empty($files['error'])) {
            return;
        }

        $requirements = array_column($this->uploadRequirements->forBorrowers(), null, 'id');
        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $borrowerNo);
        $targetDir = STORAGE_PATH . '/uploads/borrowers/' . $safeFolder;

        foreach ($files['error'] as $requirementId => $error) {
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $requirement = $requirements[$requirementId] ?? null;
            if (!$requirement) {
                continue;
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $tmpPath = $files['tmp_name'][$requirementId];
            $originalName = $files['name'][$requirementId];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $storedName = uniqid('doc_', true) . '.' . $ext;
            $destination = $targetDir . '/' . $storedName;

            if (!move_uploaded_file($tmpPath, $destination)) {
                continue;
            }

            $this->borrowers->addDocument([
                'borrower_id' => $borrowerId,
                'document_type' => $requirement['document_type'],
                'document_name' => $requirement['requirement_name'],
                'file_path' => 'uploads/borrowers/' . $safeFolder . '/' . $storedName,
                'uploaded_by' => $userId,
                'status' => 'Pending',
            ]);
        }
    }

    public function downloadDocument(string $id, string $documentId): void
    {
        Auth::authorize('borrowers.documents');
        $this->assertBranchAccess($this->borrowers->find((int) $id));

        $document = $this->borrowers->findDocument((int) $id, (int) $documentId);
        if (!$document) {
            Session::flash('error', 'Document not found.');
            $this->redirect('/borrowers/' . $id);
        }

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/borrowers/' . $id);
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    public function show(string $id): void
    {
        Auth::authorize('borrowers.view');
        $borrower = $this->borrowers->find((int) $id);

        if (!$borrower) {
            Session::flash('error', 'Borrower not found.');
            $this->redirect('/borrowers');
        }
        $this->assertBranchAccess($borrower);

        $this->view('borrowers/show', [
            'title' => 'Borrower: ' . $borrower['first_name'] . ' ' . $borrower['last_name'],
            'borrower' => $borrower,
            'loans' => $this->borrowers->loansFor((int) $id),
            'bank' => $this->borrowers->bankDetails((int) $id),
            'employment' => $this->borrowers->employmentFor((int) $id),
            'contacts' => $this->borrowers->contactsFor((int) $id),
            'documents' => $this->borrowers->documentsFor((int) $id),
            'portalUser' => $this->portalUsers->findByBorrower((int) $id),
        ]);
    }

    public function createPortalAccess(string $id): void
    {
        Auth::authorize('borrowers.portal');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/borrowers/' . $id);
        }

        $borrower = $this->borrowers->find($id);
        if (!$borrower) {
            Session::flash('error', 'Borrower not found.');
            $this->redirect('/borrowers');
        }
        $this->assertBranchAccess($borrower);

        $username = strtolower(str_replace('-', '', $borrower['borrower_no']));
        $tempPassword = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4)) . random_int(100, 999);

        $this->portalUsers->provision($id, $username, $borrower['email'], password_hash($tempPassword, PASSWORD_DEFAULT));

        $borrowerName = trim($borrower['first_name'] . ' ' . $borrower['last_name']);
        $company = (new Company())->primary();
        $brandName = ($company['brand_name'] ?? '') ?: ($company['company_name'] ?? '') ?: 'the borrower portal';
        $portalUrl = full_url('/portal/login');
        $message = "Hello $borrowerName, your $brandName borrower portal login is ready.\n"
            . "Username: $username\nPassword: $tempPassword\nLog in at: $portalUrl";

        $deliveryNotes = [];
        if (!empty($borrower['phone'])) {
            $smsResult = SmsSenderService::send((string) $borrower['phone'], $message);
            $deliveryNotes[] = $smsResult['success'] ? 'SMS sent to ' . $borrower['phone'] : 'SMS not sent (' . $smsResult['error'] . ')';
        }
        if (!empty($borrower['email'])) {
            $emailResult = EmailSenderService::send((string) $borrower['email'], 'Your ' . $brandName . ' Portal Access', $message, $borrowerName);
            $deliveryNotes[] = $emailResult['success'] ? 'email sent to ' . $borrower['email'] : 'email not sent (' . $emailResult['error'] . ')';
        }
        if (empty($deliveryNotes)) {
            $deliveryNotes[] = 'no phone or email on file -- nothing could be auto-sent';
        }

        Audit::log('Create', 'Borrower Portal', 'Provisioned/reset portal access for borrower #' . $id . ' (' . implode('; ', $deliveryNotes) . ')');
        Session::flash('success', "Portal access ready (" . implode('; ', $deliveryNotes) . "). Username: $username / Temporary password: $tempPassword -- shown here as a backup in case delivery failed, it will not be shown again.");
        $this->redirect('/borrowers/' . $id);
    }

    /** Logs staff straight into a borrower's own portal (no password) so they can see/reproduce an issue the borrower is reporting. */
    public function loginAsPortal(string $id): void
    {
        Auth::authorize('borrowers.login_as_portal');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/borrowers/' . $id);
            return;
        }

        $borrower = $this->borrowers->find($id);
        if (!$borrower) {
            Session::flash('error', 'Borrower not found.');
            $this->redirect('/borrowers');
            return;
        }
        $this->assertBranchAccess($borrower);

        $portalUser = $this->portalUsers->findByBorrower($id);
        if (!$portalUser || !$portalUser['is_active']) {
            Session::flash('error', 'This borrower does not have active portal access yet. Create portal access first.');
            $this->redirect('/borrowers/' . $id);
            return;
        }

        \App\Core\PortalAuth::loginForSupport((int) $portalUser['id']);

        $borrowerName = trim($borrower['first_name'] . ' ' . $borrower['last_name']);
        Audit::log('Impersonate', 'Borrower Portal', 'Logged in to borrower portal for ' . $borrowerName . ' (#' . $id . ') for support');

        $this->redirect('/portal/dashboard');
    }

    public function edit(string $id): void
    {
        Auth::authorize('borrowers.edit');
        $borrower = $this->borrowers->find((int) $id);

        if (!$borrower) {
            Session::flash('error', 'Borrower not found.');
            $this->redirect('/borrowers');
        }
        $this->assertBranchAccess($borrower);

        $scopeBranchId = $this->scopeBranchId();
        $this->view('borrowers/edit', [
            'title' => 'Edit Borrower',
            'branches' => $scopeBranchId === null ? $this->branches->all() : array_values(array_filter($this->branches->all(), fn($b) => (int) $b['id'] === $scopeBranchId)),
            'borrower' => $borrower,
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('borrowers.edit');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/borrowers/' . $id . '/edit');
        }

        $borrower = $this->borrowers->find($id);
        if (!$borrower) {
            Session::flash('error', 'Borrower not found.');
            $this->redirect('/borrowers');
        }
        $this->assertBranchAccess($borrower);

        $scopeBranchId = $this->scopeBranchId();
        if ($scopeBranchId !== null) {
            $_POST['branch_id'] = $scopeBranchId;
        }

        $errors = $this->validate($_POST);

        if (!empty($_POST['id_number']) && $this->borrowers->idNumberExists(trim($_POST['id_number']), $id)) {
            $errors['id_number'] = 'Another borrower already uses this ID number.';
        }

        if (!empty($_POST['loan_ref_no']) && $this->borrowers->loanRefNoExists(trim($_POST['loan_ref_no']), $id)) {
            $errors['loan_ref_no'] = 'Another borrower already uses this loan ref no.';
        }

        if (!empty($errors)) {
            $this->view('borrowers/edit', [
                'title' => 'Edit Borrower',
                'branches' => $scopeBranchId === null ? $this->branches->all() : array_values(array_filter($this->branches->all(), fn($b) => (int) $b['id'] === $scopeBranchId)),
                'borrower' => array_merge($borrower, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->borrowers->updateRecord($id, [
            'branch_id' => (int) $_POST['branch_id'],
            'loan_ref_no' => trim($_POST['loan_ref_no'] ?? '') ?: null,
            'first_name' => trim($_POST['first_name']),
            'middle_name' => trim($_POST['middle_name'] ?? '') ?: null,
            'last_name' => trim($_POST['last_name']),
            'gender' => $_POST['gender'] ?: null,
            'date_of_birth' => $_POST['date_of_birth'] ?: null,
            'id_number' => trim($_POST['id_number'] ?? '') ?: null,
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'physical_address' => trim($_POST['physical_address'] ?? '') ?: null,
            'postal_address' => trim($_POST['postal_address'] ?? '') ?: null,
            'marital_status' => $_POST['marital_status'] ?: null,
            'nationality' => trim($_POST['nationality'] ?? '') ?: 'Namibian',
            'status' => $_POST['status'] ?: $borrower['status'],
        ]);

        Audit::log('Update', 'Borrowers', 'Updated borrower #' . $id);
        Session::flash('success', 'Borrower updated successfully.');
        $this->redirect('/borrowers/' . $id);
    }

    public function destroy(string $id): void
    {
        Auth::authorize('borrowers.delete');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/borrowers');
        }

        $this->assertBranchAccess($this->borrowers->find($id));

        $this->borrowers->delete($id);
        Audit::log('Delete', 'Borrowers', 'Deleted borrower #' . $id);
        Session::flash('success', 'Borrower removed.');
        $this->redirect('/borrowers');
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (trim((string) ($data['first_name'] ?? '')) === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if (trim((string) ($data['last_name'] ?? '')) === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        if (empty($data['branch_id'])) {
            $errors['branch_id'] = 'Branch is required.';
        }
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        return $errors;
    }
}
