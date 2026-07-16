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
use App\Models\LoanApplicationBankAnalysis;
use App\Models\LoanApplicationScreening;
use App\Services\AiBankStatementAnalyzer;
use App\Services\BankStatementKeywordCategorizer;
use App\Services\BorrowerBankStatementCsvParser;
use App\Services\DocumentGenerationService;
use App\Services\TemplatedSmsService;

class ApplicationController extends Controller
{
    private LoanApplication $applications;
    private LoanApplicationScreening $screenings;
    private LoanApplicationBankAnalysis $bankAnalyses;
    private Borrower $borrowers;
    private Branch $branches;
    private DocumentTemplate $templates;
    private GeneratedDocument $documents;

    public function __construct()
    {
        $this->applications = new LoanApplication();
        $this->screenings = new LoanApplicationScreening();
        $this->bankAnalyses = new LoanApplicationBankAnalysis();
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
            'bankAnalysis' => $this->bankAnalyses->forApplication((int) $id),
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

        $proposedInstallment = (float) ($_POST['proposed_installment'] ?? 0);

        // A completed AI bank statement analysis is authoritative once it
        // exists -- real bank activity is far harder to misstate than a
        // self-reported salary, so it overrides the applicant's own figures
        // rather than staff needing to remember to prefer it manually.
        $bankAnalysis = $this->bankAnalyses->forApplication($id);
        $hasAiData = $bankAnalysis && $bankAnalysis['status'] === 'Completed' && (float) $bankAnalysis['average_monthly_income'] > 0;

        if ($hasAiData) {
            $grossSalary = (float) $bankAnalysis['average_monthly_income'];
            $netSalary = $grossSalary;
            $existingDeductions = (float) $bankAnalysis['existing_commitments_total'];
            $disposable = $grossSalary - (float) $bankAnalysis['average_monthly_expenses'] - $proposedInstallment;
            $dataSource = 'AI Bank Statement';
        } else {
            $grossSalary = (float) ($_POST['gross_salary'] ?? $application['gross_salary'] ?? 0);
            $netSalary = (float) ($_POST['net_salary'] ?? $application['net_salary'] ?? 0);
            $existingDeductions = (float) ($_POST['existing_deductions'] ?? 0);
            $disposable = $netSalary - $existingDeductions - $proposedInstallment;
            $dataSource = 'Self-Reported';
        }

        // DTI_BE = (Total Monthly Debt / Gross Monthly Income) * 100 -- total
        // monthly debt is existing deductions plus the new loan's proposed
        // installment; measured against gross (not net) income.
        $totalMonthlyDebt = $existingDeductions + $proposedInstallment;
        $dti = $grossSalary > 0 ? round(($totalMonthlyDebt / $grossSalary) * 100, 2) : 0;

        // Risk banding: under 50% Healthy, 50-70% Manageable, 70%+ High Risk.
        // Stored on the existing risk_level column (Low/Medium/High) and
        // relabeled for display -- see applications/show.php.content.
        $riskLevel = $dti < 50 ? 'Low' : ($dti < 70 ? 'Medium' : 'High');

        $userId = Auth::user()['id'] ?? null;

        $this->screenings->create([
            'application_id' => $id,
            'affordability_score' => (float) ($_POST['affordability_score'] ?? 0),
            'credit_score' => (float) ($_POST['credit_score'] ?? 0),
            'risk_level' => $riskLevel,
            'gross_salary' => $grossSalary,
            'net_salary' => $netSalary,
            'existing_deductions' => $existingDeductions,
            'proposed_installment' => $proposedInstallment,
            'disposable_income' => $disposable,
            'debt_to_income_ratio' => $dti,
            'screening_notes' => trim($_POST['screening_notes'] ?? '') ?: null,
            'recommendation' => $_POST['recommendation'] ?: 'Request More Info',
            'data_source' => $dataSource,
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

    private const BANK_STATEMENT_UPLOAD_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'csv'];
    private const BANK_STATEMENT_UPLOAD_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
    private const BANK_STATEMENT_UPLOAD_MAX_SIZE = 5 * 1024 * 1024; // 5MB, matching the public intake endpoint

    /**
     * Staff-facing manual attach for a bank statement, alongside whatever an
     * applicant already submitted through the public intake form (which
     * only accepts PDF/JPG/PNG today) -- lets staff attach one a borrower
     * sent directly (email/WhatsApp), including a CSV export, without
     * needing the external client website's own form to support it yet.
     */
    public function uploadBankStatement(string $id): void
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

        $documentType = ($_POST['document_type'] ?? '') === 'Bank Statement (Merged)' ? 'Bank Statement (Merged)' : 'Bank Statement';

        $file = $_FILES['bank_statement_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            Session::flash('error', 'Choose a bank statement file to upload.');
            $this->redirect('/applications/' . $id);
            return;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] <= 0 || $file['size'] > self::BANK_STATEMENT_UPLOAD_MAX_SIZE) {
            Session::flash('error', 'Upload failed: invalid or oversized file (max 5MB).');
            $this->redirect('/applications/' . $id);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::BANK_STATEMENT_UPLOAD_EXTENSIONS, true)) {
            Session::flash('error', 'Only PDF, JPG, PNG, or CSV files are accepted.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($realMime, self::BANK_STATEMENT_UPLOAD_MIMES, true)) {
            Session::flash('error', 'Upload failed: the file content does not match an accepted PDF/image/CSV type.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $application['application_no']);
        $targetDir = STORAGE_PATH . '/uploads/loan_applications/' . $safeFolder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $storedName = 'bank_statement_' . uniqid('', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $targetDir . '/' . $storedName)) {
            Session::flash('error', 'Could not save the uploaded file.');
            $this->redirect('/applications/' . $id);
            return;
        }

        $this->applications->addDocument([
            'application_id' => $id,
            'borrower_id' => $application['borrower_id'] ?: null,
            'document_type' => $documentType,
            'document_name' => $file['name'],
            'file_path' => 'uploads/loan_applications/' . $safeFolder . '/' . $storedName,
            'status' => 'Pending',
            'uploaded_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Upload', 'Applications', 'Uploaded bank statement (' . $documentType . ') for application #' . $id);
        Session::flash('success', 'Bank statement uploaded.');
        $this->redirect('/applications/' . $id);
    }

    /**
     * Sends the applicant's uploaded bank statement document(s) for
     * structured extraction (income, expenses, existing commitments, NSF
     * count) so staff get one consolidated read whether the applicant
     * uploaded a single merged statement or up to three separate ones.
     * Routes to whichever pipeline matches the file format: a CSV is
     * already structured data, so it goes through the deterministic
     * keyword-based BorrowerBankStatementCsvParser + BankStatementKeywordCategorizer
     * pipeline rather than the AI -- see AiBankStatementAnalyzer for the
     * PDF/image path and how its two upload shapes are reconciled.
     */
    public function analyzeBankStatements(string $id): void
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

        $userId = Auth::user()['id'] ?? null;
        $bankStatementDocs = array_values(array_filter(
            $this->applications->documents($id),
            fn ($d) => in_array($d['document_type'], ['Bank Statement (Merged)', 'Bank Statement'], true)
        ));
        $csvDocs = array_values(array_filter(
            $bankStatementDocs,
            fn ($d) => strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION)) === 'csv'
        ));

        if (!empty($csvDocs)) {
            // A CSV is already structured data and analyzed alone through the
            // deterministic keyword pipeline -- it can't be merged with a
            // PDF/image in one run. The most recently uploaded CSV is used;
            // any PDF/image files present are simply not part of this run
            // (still available to analyze separately).
            $csvDoc = end($csvDocs);
            $parsed = BorrowerBankStatementCsvParser::parse(STORAGE_PATH . '/' . $csvDoc['file_path']);

            if (!empty($parsed['errors'])) {
                $errorMessage = implode(' ', $parsed['errors']);
                $this->bankAnalyses->create([
                    'application_id' => $id,
                    'statement_format' => $csvDoc['document_type'] === 'Bank Statement (Merged)' ? 'Merged' : 'Separate',
                    'ingestion_method' => 'CSV Keyword Rules',
                    'status' => 'Failed',
                    'error_message' => $errorMessage,
                    'analyzed_by' => $userId,
                    'analyzed_at' => date('Y-m-d H:i:s'),
                ]);
                Audit::log('AI Analysis', 'Applications', 'CSV bank statement analysis failed for application #' . $id . ': ' . $errorMessage);
                Session::flash('error', 'CSV analysis failed: ' . $errorMessage);
                $this->redirect('/applications/' . $id);
                return;
            }

            $data = BankStatementKeywordCategorizer::categorize($parsed['rows']);
            $ingestionMethod = 'CSV Keyword Rules';
            $statementFormat = $csvDoc['document_type'] === 'Bank Statement (Merged)' ? 'Merged' : 'Separate';
            $documentIds = [(int) $csvDoc['id']];
            $modelUsed = null;
        } else {
            if (empty($bankStatementDocs)) {
                Session::flash('error', 'No bank statement documents have been uploaded for this application yet.');
                $this->redirect('/applications/' . $id);
                return;
            }

            $result = AiBankStatementAnalyzer::analyze($bankStatementDocs);

            if (!$result['success']) {
                $this->bankAnalyses->create([
                    'application_id' => $id,
                    'statement_format' => 'Separate',
                    'ingestion_method' => 'AI Document Analysis',
                    'status' => 'Failed',
                    'error_message' => $result['error'],
                    'analyzed_by' => $userId,
                    'analyzed_at' => date('Y-m-d H:i:s'),
                ]);
                Audit::log('AI Analysis', 'Applications', 'Bank statement analysis failed for application #' . $id . ': ' . $result['error']);
                Session::flash('error', 'AI analysis failed: ' . $result['error']);
                $this->redirect('/applications/' . $id);
                return;
            }

            $data = $result['data'];
            $ingestionMethod = 'AI Document Analysis';
            $statementFormat = $result['format'];
            $documentIds = $result['documentIds'];
            $modelUsed = $result['modelUsed'];
        }

        $income = (float) ($data['average_monthly_income'] ?? 0);
        $expenses = (float) ($data['average_monthly_expenses'] ?? 0);
        $periodStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['analysis_period_start'] ?? '')) ? $data['analysis_period_start'] : null;
        $periodEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['analysis_period_end'] ?? '')) ? $data['analysis_period_end'] : null;

        $this->bankAnalyses->create([
            'application_id' => $id,
            'source_document_ids' => json_encode($documentIds),
            'statement_format' => $statementFormat,
            'ingestion_method' => $ingestionMethod,
            'months_covered' => (int) ($data['months_covered'] ?? 0),
            'analysis_period_start' => $periodStart,
            'analysis_period_end' => $periodEnd,
            'average_monthly_income' => $income,
            'average_monthly_expenses' => $expenses,
            'net_monthly_cash_flow' => round($income - $expenses, 2),
            'average_closing_balance' => (float) ($data['average_closing_balance'] ?? 0),
            'existing_commitments_total' => (float) ($data['existing_commitments_total'] ?? 0),
            'existing_commitments' => json_encode($data['existing_commitments'] ?? []),
            'expense_breakdown' => json_encode($data['expense_breakdown'] ?? []),
            'transactions' => json_encode($data['transactions'] ?? []),
            'risk_flags' => json_encode($data['risk_flags'] ?? []),
            'nsf_count' => (int) ($data['nsf_count'] ?? 0),
            'ai_summary' => (string) ($data['summary'] ?? ''),
            'model_used' => $modelUsed,
            'status' => 'Completed',
            'analyzed_by' => $userId,
            'analyzed_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('AI Analysis', 'Applications', 'Bank statement analysis completed for application #' . $id . ' (' . $ingestionMethod . ', ' . $statementFormat . ', ' . count($documentIds) . ' file(s))');
        Session::flash('success', 'Bank statement analysis complete.');
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

        $applicantName = trim($application['applicant_first_name'] . ' ' . $application['applicant_last_name']);
        $smsResult = TemplatedSmsService::send(
            'APPLICATION_APPROVED_SMS',
            (string) ($application['applicant_phone'] ?? ''),
            ['borrower_full_name' => $applicantName, 'application_no' => $application['application_no']],
            $application['borrower_id'] ?? null,
            $userId
        );
        $deliveryNote = $smsResult['note'];

        Audit::log('Approve', 'Applications', 'Approved application #' . $id . ' (' . $deliveryNote . ')');
        Session::flash('success', 'Application approved (' . $deliveryNote . '). Convert it to a borrower/loan below when ready.');
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

        $applicantName = trim($application['applicant_first_name'] . ' ' . $application['applicant_last_name']);
        $smsResult = TemplatedSmsService::send(
            'APPLICATION_REJECTED_SMS',
            (string) ($application['applicant_phone'] ?? ''),
            ['borrower_full_name' => $applicantName, 'application_no' => $application['application_no']],
            $application['borrower_id'] ?? null,
            $userId
        );

        Audit::log('Reject', 'Applications', 'Rejected application #' . $id . ' (' . $smsResult['note'] . ')');
        Session::flash('success', 'Application rejected (' . $smsResult['note'] . ').');
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
