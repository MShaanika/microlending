<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\LoanApplication;
use App\Models\LoanApplicationScreening;
use App\Services\DocumentGenerationService;

class ApplicationController extends Controller
{
    private LoanApplication $applications;
    private LoanApplicationScreening $screenings;
    private Borrower $borrowers;
    private Branch $branches;
    private DocumentTemplate $templates;
    private GeneratedDocument $documents;

    public function __construct()
    {
        $this->applications = new LoanApplication();
        $this->screenings = new LoanApplicationScreening();
        $this->borrowers = new Borrower();
        $this->branches = new Branch();
        $this->templates = new DocumentTemplate();
        $this->documents = new GeneratedDocument();
    }

    public function index(): void
    {
        Auth::authorize('applications.view');
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('applications/index', [
            'title' => 'Online Loan Applications',
            'applications' => $this->applications->paginated($status),
            'status' => $status,
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('applications.view');
        $application = $this->applications->find((int) $id);

        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }

        $this->view('applications/show', [
            'title' => 'Application ' . $application['application_no'],
            'application' => $application,
            'documents' => $this->applications->documents((int) $id),
            'screening' => $this->screenings->forApplication((int) $id),
            'history' => $this->applications->statusHistory((int) $id),
            'extra' => $application['extra_data'] ? json_decode($application['extra_data'], true) : [],
            'branches' => $this->branches->all(),
        ]);
    }

    public function screen(string $id): void
    {
        Auth::authorize('applications.screen');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }

        $grossSalary = (float) ($_POST['gross_salary'] ?? $application['gross_salary'] ?? 0);
        $netSalary = (float) ($_POST['net_salary'] ?? $application['net_salary'] ?? 0);
        $existingDeductions = (float) ($_POST['existing_deductions'] ?? 0);
        $proposedInstallment = (float) ($_POST['proposed_installment'] ?? 0);
        $disposable = $netSalary - $existingDeductions - $proposedInstallment;
        $dti = $netSalary > 0 ? round((($existingDeductions + $proposedInstallment) / $netSalary) * 100, 2) : 0;

        $userId = Auth::user()['id'] ?? null;

        $this->screenings->create([
            'application_id' => $id,
            'affordability_score' => (float) ($_POST['affordability_score'] ?? 0),
            'credit_score' => (float) ($_POST['credit_score'] ?? 0),
            'risk_level' => $_POST['risk_level'] ?: 'Medium',
            'gross_salary' => $grossSalary,
            'net_salary' => $netSalary,
            'existing_deductions' => $existingDeductions,
            'proposed_installment' => $proposedInstallment,
            'disposable_income' => $disposable,
            'debt_to_income_ratio' => $dti,
            'screening_notes' => trim($_POST['screening_notes'] ?? '') ?: null,
            'recommendation' => $_POST['recommendation'] ?: 'Request More Info',
            'screened_by' => $userId,
            'screened_at' => date('Y-m-d H:i:s'),
        ]);

        $this->applications->updateRecord($id, [
            'status' => 'Screening',
            'screened_by' => $userId,
            'screened_at' => date('Y-m-d H:i:s'),
        ]);
        $this->applications->addStatusHistory($id, $application['status'], 'Screening', $userId, 'Affordability screening recorded.');

        Audit::log('Screen', 'Applications', 'Recorded screening for application #' . $id);
        Session::flash('success', 'Screening recorded.');
        $this->redirect('/applications/' . $id);
    }

    public function approve(string $id): void
    {
        Auth::authorize('applications.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application || in_array($application['status'], ['Approved', 'Converted to Loan', 'Rejected', 'Cancelled'], true)) {
            Session::flash('error', 'This application cannot be approved from its current status.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $this->applications->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $this->applications->addStatusHistory($id, $application['status'], 'Approved', $userId, trim($_POST['notes'] ?? '') ?: null);

        Audit::log('Approve', 'Applications', 'Approved application #' . $id);
        Session::flash('success', 'Application approved. Convert it to a borrower/loan below when ready.');
        $this->redirect('/applications/' . $id);
    }

    public function reject(string $id): void
    {
        Auth::authorize('applications.reject');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application || in_array($application['status'], ['Rejected', 'Converted to Loan', 'Cancelled'], true)) {
            Session::flash('error', 'This application cannot be rejected from its current status.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            Session::flash('error', 'A rejection reason is required.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $this->applications->updateRecord($id, [
            'status' => 'Rejected',
            'rejection_reason' => $reason,
            'rejected_by' => $userId,
            'rejected_at' => date('Y-m-d H:i:s'),
        ]);
        $this->applications->addRejection([
            'application_id' => $id,
            'borrower_id' => $application['borrower_id'],
            'rejection_reason' => $reason,
            'rejection_category' => $_POST['rejection_category'] ?: 'Other',
            'rejected_by' => $userId,
        ]);
        $this->applications->addStatusHistory($id, $application['status'], 'Rejected', $userId, $reason);

        Audit::log('Reject', 'Applications', 'Rejected application #' . $id);
        Session::flash('success', 'Application rejected.');
        $this->redirect('/applications/' . $id);
    }

    /**
     * Turns an approved application into a Borrower (if the applicant is
     * new) and hands staff off to the New Loan screen to pick a product and
     * plan -- mirrors the existing LoanRequestController::approve() handoff
     * rather than guessing product/plan here.
     */
    public function convert(string $id): void
    {
        Auth::authorize('applications.convert');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application || $application['status'] !== 'Approved') {
            Session::flash('error', 'Only approved applications can be converted.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $borrowerId = (int) ($application['borrower_id'] ?? 0);

        if (!$borrowerId) {
            if (empty($_POST['branch_id'])) {
                Session::flash('error', 'Select a branch for the new borrower before converting.');
                $this->redirect('/applications/' . $id);
                return;
            }

            $userId = Auth::user()['id'] ?? null;
            $borrowerData = [
                'branch_id' => (int) $_POST['branch_id'],
                'borrower_no' => generate_reference('BRW'),
                'first_name' => $application['applicant_first_name'] ?? '',
                'middle_name' => $application['applicant_middle_name'] ?: null,
                'last_name' => $application['applicant_last_name'] ?? '',
                'gender' => $application['applicant_gender'] ?: null,
                'id_number' => $application['applicant_id_number'] ?: null,
                'phone' => $application['applicant_phone'] ?: null,
                'email' => $application['applicant_email'] ?: null,
                'physical_address' => $application['applicant_address'] ?: null,
                'status' => 'Pending',
                'created_by' => $userId,
            ];

            $bank = ($application['bank_name'] || $application['bank_account_number']) ? [
                'bank_name' => $application['bank_name'] ?: null,
                'account_name' => $application['bank_account_name'] ?: null,
                'account_number' => $application['bank_account_number'] ?: null,
                'branch_code' => $application['bank_branch_code'] ?: null,
                'is_primary' => 1,
            ] : null;

            $employment = $application['employer_name'] ? [
                'employer_name' => $application['employer_name'],
                'employee_no' => $application['employee_no'] ?: null,
                'gross_salary' => (float) $application['gross_salary'],
                'net_salary' => (float) $application['net_salary'],
                'payment_day' => $application['payment_day'] ?: null,
                'is_current' => 1,
            ] : null;

            $borrowerId = $this->borrowers->createFull($borrowerData, $bank, $employment, []);

            // Carry the applicant's uploaded KYC documents (payslip, ID copy,
            // bank statements) across so staff don't have to re-collect them.
            foreach ($this->applications->documents($id) as $doc) {
                if (str_starts_with($doc['document_type'], 'Signature')) {
                    continue;
                }
                $this->borrowers->addDocument([
                    'borrower_id' => $borrowerId,
                    'document_type' => $doc['document_type'],
                    'document_name' => $doc['document_name'],
                    'file_path' => $doc['file_path'],
                    'uploaded_by' => $userId,
                    'status' => 'Pending',
                ]);
            }

            $this->applications->updateRecord($id, ['borrower_id' => $borrowerId]);
            Audit::log('Create', 'Borrowers', 'Created borrower #' . $borrowerId . ' from application ' . $application['application_no']);
        }

        Session::flash('success', 'Applicant ready. Pick a loan product and plan to finish converting ' . $application['application_no'] . '.');
        $this->redirect('/loans/create?borrower_id=' . $borrowerId . '&application_id=' . $id
            . '&amount=' . urlencode((string) $application['requested_amount'])
            . '&purpose=' . urlencode((string) ($application['requested_purpose'] ?? '')));
    }

    public function downloadDocument(string $id, string $documentId): void
    {
        Auth::authorize('applications.view');
        $document = $this->applications->findDocument((int) $id, (int) $documentId);

        if (!$document) {
            Session::flash('error', 'Document not found.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/applications/' . $id);
            return;
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

    /**
     * Generates an application-stage document (Consent, Affordability
     * Assessment, Loan Agreement, ...) using the same reusable engine as
     * borrower letters (DocumentGenerationService). Requires the template +
     * its field mapping to already be configured under Settings.
     */
    public function generateDocument(string $id, string $templateCode): void
    {
        Auth::authorize('applications.view');
        $id = (int) $id;

        $application = $this->applications->find($id);
        if (!$application) {
            Session::flash('error', 'Application not found.');
            $this->redirect('/applications');
            return;
        }

        $template = $this->templates->findByCode($templateCode);
        if (!$template) {
            Session::flash('error', 'That document template is not configured yet.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $documentId = $this->documents->create([
            'template_id' => $template['id'],
            'document_no' => generate_reference('DOC'),
            'document_title' => $template['template_name'] . ' - ' . $application['application_no'],
            'borrower_id' => $application['borrower_id'] ?: null,
            'application_id' => $id,
            'source_module' => 'Application',
            'status' => 'Draft',
        ]);

        $document = $this->documents->find($documentId);

        try {
            $filePath = DocumentGenerationService::generate($document);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/applications/' . $id);
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
