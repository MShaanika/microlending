<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\AgentCommission;
use App\Models\AgentCommissionEntry;
use App\Models\Branch;
use App\Models\HrmEmployee;
use App\Models\LoanApplication;
use App\Support\PhoneNumberNormalizer;

/**
 * Marketing agent self-service: submit a new borrower referral and see
 * their own running commission totals. Every action is gated by
 * Auth::requireLogin() + a linked, commission-eligible employee record --
 * mirrors EmployeeSelfServiceController::resolveEmployee() exactly, and
 * never accepts an employee/agent id from the request.
 */
class AgentSelfServiceController extends Controller
{
    private HrmEmployee $employees;
    private LoanApplication $applications;
    private AgentCommission $commissions;
    private AgentCommissionEntry $commissionEntries;
    private Branch $branches;

    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const ALLOWED_DOCUMENT_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024; // 5MB
    /** field name => [document_type, document_name] stored on loan_application_documents, same set the public apply form collects. */
    private const DOCUMENT_FIELDS = [
        'id_copy' => ['ID Copy', 'ID Copy'],
        'payslip' => ['Payslip', 'Payslip'],
        'bank_statement_merged' => ['Bank Statement', 'Bank Statement (Merged)'],
        'bank_statement_1' => ['Bank Statement', 'Bank Statement'],
        'bank_statement_2' => ['Bank Statement', 'Bank Statement'],
        'bank_statement_3' => ['Bank Statement', 'Bank Statement'],
    ];

    public function __construct()
    {
        $this->employees = new HrmEmployee();
        $this->applications = new LoanApplication();
        $this->commissions = new AgentCommission();
        $this->commissionEntries = new AgentCommissionEntry();
        $this->branches = new Branch();
    }

    private function resolveAgent(): ?array
    {
        Auth::requireLogin();
        $employee = $this->employees->findByUserId((int) (Auth::user()['id'] ?? 0));
        if (!$employee || (int) $employee['is_commission_agent'] !== 1) {
            Session::flash('error', 'Your account is not set up as a marketing agent.');
            $this->redirect('/dashboard');
            return null;
        }
        return $employee;
    }

    public function dashboard(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $rows = $this->commissions->allForAgent((int) $agent['id']);
        $earned = array_sum(array_column($rows, 'earned_amount'));
        $paid = array_sum(array_column($rows, 'paid_amount'));

        $this->view('my/agent/dashboard', [
            'title' => 'My Referrals',
            'agent' => $agent,
            'referralLink' => $agent['referral_code']
                ? public_site_url('/apply-dg.php?refId=' . urlencode((string) $agent['referral_code']))
                : null,
            'totals' => [
                'total_commission' => array_sum(array_column($rows, 'total_commission_amount')),
                'earned' => $earned,
                'paid' => $paid,
                'outstanding' => round($earned - $paid, 2),
            ],
            'recentCommissions' => array_slice($rows, 0, 5),
        ]);
    }

    public function referralCreate(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $this->view('my/agent/referral_create', [
            'title' => 'New Referral',
            'agent' => $agent,
            'branches' => empty($agent['branch_id']) ? $this->branches->all() : [],
            'old' => [],
            'errors' => [],
        ]);
    }

    public function referralStore(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/my/referrals/create');
            return;
        }

        [$data, $errors] = $this->validateReferral($_POST, $agent);
        $errors = array_merge($errors, $this->validateDocumentUploads($_FILES));

        if (!empty($errors)) {
            $this->view('my/agent/referral_create', [
                'title' => 'New Referral',
                'agent' => $agent,
                'branches' => empty($agent['branch_id']) ? $this->branches->all() : [],
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $applicationNo = generate_reference('APP');
        $data['application_no'] = $applicationNo;
        $data['application_source'] = 'Back Office';
        $data['application_type'] = 'New Loan';
        $data['status'] = 'Submitted';
        $data['agent_id'] = (int) $agent['id'];

        $applicationId = $this->applications->create($data);
        $this->applications->addStatusHistory(
            $applicationId,
            null,
            'Submitted',
            Auth::user()['id'] ?? null,
            'Submitted by marketing agent ' . $agent['first_name'] . ' ' . $agent['last_name'] . '.'
        );
        $this->storeDocumentUploads($applicationId, $applicationNo, $_FILES);

        Audit::log('Create', 'Commissions', 'Application ' . $applicationNo . ' referred by agent #' . $agent['id']);
        Session::flash('success', 'Referral submitted as application ' . $applicationNo . '. Staff will review and process it.');
        $this->redirect('/my/referrals');
    }

    public function referralIndex(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $this->view('my/agent/referral_index', [
            'title' => 'My Referrals',
            'agent' => $agent,
            'applications' => $this->applications->allForAgent((int) $agent['id']),
        ]);
    }

    public function commissions(): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $this->view('my/agent/commissions', [
            'title' => 'My Commissions',
            'agent' => $agent,
            'rows' => $this->commissions->allForAgent((int) $agent['id']),
        ]);
    }

    public function commissionShow(string $id): void
    {
        $agent = $this->resolveAgent();
        if (!$agent) {
            return;
        }

        $commission = $this->commissions->find((int) $id);
        if (!$commission || (int) $commission['agent_employee_id'] !== (int) $agent['id']) {
            Session::flash('error', 'Commission record not found.');
            $this->redirect('/my/commissions');
            return;
        }

        $this->view('my/agent/commission_show', [
            'title' => 'Commission Detail',
            'agent' => $agent,
            'commission' => $commission,
            'entries' => $this->commissionEntries->forCommission((int) $commission['id']),
        ]);
    }

    /**
     * @return array{0: array, 1: array} [canonical loan_applications data, field => error message]
     */
    private function validateReferral(array $post, array $agent): array
    {
        $errors = [];

        $firstName = trim($post['applicant_first_name'] ?? '');
        $lastName = trim($post['applicant_last_name'] ?? '');
        $idNumber = trim($post['applicant_id_number'] ?? '');
        $phone = trim($post['applicant_phone'] ?? '');
        // An agent tied to a branch always refers into that same branch --
        // no picker needed. Only an unassigned agent sees/uses one.
        $branchId = !empty($agent['branch_id']) ? (int) $agent['branch_id'] : (!empty($post['branch_id']) ? (int) $post['branch_id'] : null);
        $requestedAmount = (float) ($post['requested_amount'] ?? 0);

        if ($firstName === '') {
            $errors['applicant_first_name'] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors['applicant_last_name'] = 'Last name is required.';
        }
        if ($idNumber === '') {
            $errors['applicant_id_number'] = 'ID number is required.';
        }
        if ($phone === '') {
            $errors['applicant_phone'] = 'Phone number is required.';
        }
        if (!$branchId) {
            $errors['branch_id'] = 'Select a branch.';
        }
        if ($requestedAmount <= 0) {
            $errors['requested_amount'] = 'Enter the amount the client wants to borrow.';
        }

        $data = [
            'branch_id' => $branchId,
            'applicant_first_name' => $firstName,
            'applicant_middle_name' => trim($post['applicant_middle_name'] ?? '') ?: null,
            'applicant_last_name' => $lastName,
            'applicant_id_number' => $idNumber,
            'applicant_phone' => $phone !== '' ? PhoneNumberNormalizer::toE164($phone) : '',
            'applicant_email' => trim($post['applicant_email'] ?? '') ?: null,
            'applicant_gender' => in_array($post['applicant_gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $post['applicant_gender'] : null,
            'applicant_address' => trim($post['applicant_address'] ?? '') ?: null,
            'employer_name' => trim($post['employer_name'] ?? '') ?: null,
            'employee_no' => trim($post['employee_no'] ?? '') ?: null,
            'gross_salary' => (float) ($post['gross_salary'] ?? 0),
            'net_salary' => (float) ($post['net_salary'] ?? 0),
            'payment_day' => !empty($post['payment_day']) ? (int) $post['payment_day'] : null,
            'bank_name' => trim($post['bank_name'] ?? '') ?: null,
            'bank_account_name' => trim($post['account_name'] ?? '') ?: null,
            'bank_account_number' => trim($post['account_number'] ?? '') ?: null,
            'bank_branch_code' => trim($post['bank_branch_code'] ?? '') ?: null,
            'requested_amount' => $requestedAmount,
            'requested_term_months' => (int) ($post['requested_term_months'] ?? 1),
            'requested_purpose' => trim($post['requested_purpose'] ?? '') ?: null,
        ];

        // Fields loan_applications has no dedicated column for -- kept
        // alongside the canonical ones so staff reviewing/converting this
        // application to a Borrower see the agent's full intake, not just
        // the columns that happened to already exist on this table.
        $extra = array_filter([
            'job_title' => trim($post['job_title'] ?? ''),
            'employment_type' => in_array($post['employment_type'] ?? '', ['Permanent', 'Contract', 'Self-Employed', 'Government', 'Casual', 'Other'], true) ? $post['employment_type'] : '',
            'employment_start_date' => trim($post['employment_start_date'] ?? ''),
            'employer_phone' => trim($post['employer_phone'] ?? ''),
            'employer_email' => trim($post['employer_email'] ?? ''),
            'employer_address' => trim($post['employer_address'] ?? ''),
            'bank_account_type' => in_array($post['account_type'] ?? '', ['Savings', 'Cheque', 'Current'], true) ? $post['account_type'] : '',
            'bank_branch_name' => trim($post['bank_branch_name'] ?? ''),
        ], fn($v) => $v !== '');

        $affordability = $this->collectAffordability($post);
        if ($affordability) {
            $extra['affordability'] = $affordability;
        }

        $nextOfKin = $this->collectNextOfKin($post);
        if ($nextOfKin) {
            $extra['next_of_kin'] = $nextOfKin;
        }

        $data['extra_data'] = empty($extra) ? null : json_encode($extra);

        return [$data, $errors];
    }

    /**
     * Same worksheet/shape as BorrowerController::collectAffordability() --
     * kept as a separate copy since this one lands in extra_data on an
     * application row rather than its own borrower_affordability record
     * (there's no borrower yet at referral time).
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

    private function collectNextOfKin(array $post): array
    {
        $rows = [];
        foreach ($post['contacts'] ?? [] as $contact) {
            $fullName = trim($contact['full_name'] ?? '');
            if ($fullName === '') {
                continue;
            }
            $rows[] = [
                'contact_type' => trim($contact['contact_type'] ?? '') ?: 'Next of Kin',
                'full_name' => $fullName,
                'relationship' => trim($contact['relationship'] ?? ''),
                'phone' => trim($contact['phone'] ?? ''),
                'email' => trim($contact['email'] ?? ''),
                'address' => trim($contact['address'] ?? ''),
            ];
        }
        return $rows;
    }

    private function validateDocumentUploads(array $files): array
    {
        $errors = [];
        foreach (array_keys(self::DOCUMENT_FIELDS) as $field) {
            $file = $files[$field] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[$field] = 'Upload failed. Please try again.';
                continue;
            }
            if ($file['size'] > self::MAX_DOCUMENT_SIZE) {
                $errors[$field] = 'File is too large (max 5MB).';
                continue;
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
                $errors[$field] = 'Only PDF, JPG and PNG files are allowed.';
            }
        }
        return $errors;
    }

    private function storeDocumentUploads(int $applicationId, string $applicationNo, array $files): void
    {
        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $applicationNo);
        $targetDir = STORAGE_PATH . '/uploads/loan_applications/' . $safeFolder;

        foreach (self::DOCUMENT_FIELDS as $field => [$documentType, $documentName]) {
            $file = $files[$field] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                continue;
            }

            // Real MIME sniffing, not just the client-supplied extension/type.
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($realMime, self::ALLOWED_DOCUMENT_MIMES, true)) {
                continue;
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedName = $field . '_' . uniqid('', true) . '.' . $ext;
            $destination = $targetDir . '/' . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                continue;
            }

            $this->applications->addDocument([
                'application_id' => $applicationId,
                'document_type' => $documentType,
                'document_name' => $documentName,
                'file_path' => 'uploads/loan_applications/' . $safeFolder . '/' . $storedName,
                'status' => 'Pending',
            ]);
        }
    }
}
