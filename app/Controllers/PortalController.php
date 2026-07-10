<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\PortalAuth;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRequest;
use App\Models\Payment;
use App\Models\RefundClaim;
use App\Models\UploadRequirement;

class PortalController extends Controller
{
    private Loan $loans;
    private Payment $payments;
    private LoanRequest $loanRequests;
    private LoanProduct $products;
    private Borrower $borrowers;
    private Company $companies;
    private DocumentTemplate $documentTemplates;
    private GeneratedDocument $generatedDocuments;
    private RefundClaim $refundClaims;
    private UploadRequirement $uploadRequirements;

    private const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_DOCUMENT_SIZE = 5 * 1024 * 1024; // 5MB

    public function __construct()
    {
        $this->loans = new Loan();
        $this->payments = new Payment();
        $this->loanRequests = new LoanRequest();
        $this->products = new LoanProduct();
        $this->borrowers = new Borrower();
        $this->companies = new Company();
        $this->documentTemplates = new DocumentTemplate();
        $this->generatedDocuments = new GeneratedDocument();
        $this->refundClaims = new RefundClaim();
        $this->uploadRequirements = new UploadRequirement();
    }

    public function dashboard(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/dashboard', [
            'title' => 'My Dashboard',
            'loans' => $this->loans->forBorrower($borrowerId),
        ]);
    }

    public function loans(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/loans/index', [
            'title' => 'My Loans',
            'loans' => $this->loans->forBorrower($borrowerId),
        ]);
    }

    public function loanShow(string $id): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $loan = $this->loans->findForBorrower((int) $id, $borrowerId);
        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/portal/loans');
        }

        $this->view('portal/loans/show', [
            'title' => 'Loan ' . $loan['loan_no'],
            'loan' => $loan,
            'schedule' => $this->loans->schedule((int) $id),
        ]);
    }

    /**
     * Printable loan invoice / statement of account. No PDF library is
     * installed, so this renders clean, print-ready HTML instead --
     * "Print" in the browser produces a perfectly good PDF via Save as PDF.
     */
    public function loanInvoice(string $id): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $loan = $this->loans->findForBorrower((int) $id, $borrowerId);
        if (!$loan) {
            Session::flash('error', 'Loan not found.');
            $this->redirect('/portal/loans');
        }

        $this->view('portal/loans/invoice', [
            'title' => 'Invoice - ' . $loan['loan_no'],
            'loan' => $loan,
            'schedule' => $this->loans->schedule((int) $id),
            'borrower' => $this->borrowers->find($borrowerId),
            'company' => $this->companies->primary(),
        ]);
    }

    public function loanRequestCreate(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/loan_requests/create', [
            'title' => 'Request a Loan',
            'products' => $this->products->activeWithPlans(),
            'existingLoans' => $this->loans->forBorrower($borrowerId),
            'documentRequirements' => $this->uploadRequirements->forBorrowers(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function loanRequestStore(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/portal/loan-requests/create');
        }

        $requestType = ($_POST['request_type'] ?? '') === 'Top Up' ? 'Top Up' : 'New';
        $amount = (float) ($_POST['requested_amount'] ?? 0);
        $term = (int) ($_POST['requested_term_months'] ?? 0);
        $existingLoanId = (int) ($_POST['existing_loan_id'] ?? 0);

        $errors = [];
        if ($amount <= 0) {
            $errors['requested_amount'] = 'Enter a valid amount.';
        }
        if ($term <= 0) {
            $errors['requested_term_months'] = 'Enter a valid term in months.';
        }

        $existingLoan = null;
        if ($requestType === 'Top Up') {
            $existingLoan = $this->loans->findForBorrower($existingLoanId, $borrowerId);
            if (!$existingLoan) {
                $errors['existing_loan_id'] = 'Select one of your loans to top up.';
            }
        }

        $documentErrors = [];
        if ($requestType === 'New') {
            $documentErrors = $this->validateDocumentUploads($_FILES['documents'] ?? []);
            if (empty($documentErrors) && empty(array_filter($_FILES['documents']['error'] ?? [], fn ($e) => $e === UPLOAD_ERR_OK))) {
                $documentErrors['documents'] = 'Please attach at least one supporting document for a new loan request.';
            }
        }
        $errors = array_merge($errors, $documentErrors);

        if (!empty($errors)) {
            $this->view('portal/loan_requests/create', [
                'title' => 'Request a Loan',
                'products' => $this->products->activeWithPlans(),
                'existingLoans' => $this->loans->forBorrower($borrowerId),
                'documentRequirements' => $this->uploadRequirements->forBorrowers(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $borrower = $this->borrowers->find($borrowerId);
        $requestNo = generate_reference('LRQ');

        $requestId = $this->loanRequests->create([
            'borrower_id' => $borrowerId,
            'request_type' => $requestType,
            'existing_loan_id' => $requestType === 'Top Up' ? $existingLoanId : null,
            'branch_id' => $borrower['branch_id'] ?? null,
            'request_no' => $requestNo,
            'requested_amount' => $amount,
            'requested_term_months' => $term,
            'purpose' => trim($_POST['purpose'] ?? '') ?: null,
            'status' => 'Pending',
        ]);

        if ($requestType === 'New') {
            $this->storeLoanRequestDocuments($requestId, $requestNo, $_FILES['documents'] ?? []);
        }

        Audit::log('Create', 'Loan Requests', 'Borrower portal ' . strtolower($requestType) . ' loan request #' . $requestId . ' by borrower #' . $borrowerId);
        Session::flash('success', 'Your loan request has been submitted. Our team will review it shortly.');
        $this->redirect('/portal/loan-requests');
    }

    public function loanRequestsIndex(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/loan_requests/index', [
            'title' => 'My Loan Requests',
            'requests' => $this->loanRequests->forBorrower($borrowerId),
        ]);
    }

    public function paymentCreate(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/payments/create', [
            'title' => 'Log a Payment',
            'loans' => $this->loans->forBorrower($borrowerId),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function paymentStore(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/portal/payments/create');
        }

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $loan = $this->loans->findForBorrower($loanId, $borrowerId);
        $amount = (float) ($_POST['amount_received'] ?? 0);

        $errors = [];
        if (!$loan) {
            $errors['loan_id'] = 'Select a valid loan.';
        }
        if ($amount <= 0) {
            $errors['amount_received'] = 'Enter a payment amount greater than zero.';
        }

        if (!empty($errors)) {
            $this->view('portal/payments/create', [
                'title' => 'Log a Payment',
                'loans' => $this->loans->forBorrower($borrowerId),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $paymentId = $this->payments->logPendingReference($loan, $amount, [
            'payment_date' => $_POST['payment_date'] ?: date('Y-m-d'),
            'payment_source' => $_POST['payment_source'] ?: 'Bank Transfer',
            'reference_no' => trim($_POST['reference_no'] ?? ''),
            'payer_name' => trim($_POST['payer_name'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
        ]);

        Audit::log('Create', 'Collections', 'Borrower portal logged pending payment #' . $paymentId . ' for loan #' . $loanId);
        Session::flash('success', 'Payment reference submitted. Our team will confirm it and update your balance shortly.');
        $this->redirect('/portal/payments');
    }

    public function paymentsIndex(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/payments/index', [
            'title' => 'My Payments',
            'payments' => $this->payments->forBorrower($borrowerId),
        ]);
    }

    // ---------------------------------------------------------------
    // Letters (Completion / Consolidation) -- request-and-fulfil workflow
    // ---------------------------------------------------------------

    public function letters(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/letters/index', [
            'title' => 'My Letters',
            'documents' => $this->generatedDocuments->forBorrower($borrowerId),
        ]);
    }

    public function letterCreate(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/letters/create', [
            'title' => 'Request a Letter',
            'loans' => $this->loans->forBorrower($borrowerId),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function letterStore(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/portal/letters/create');
        }

        $letterType = $_POST['letter_type'] ?? '';
        $scope = $_POST['consolidation_scope'] ?? 'one'; // 'one' or 'all'
        $loanId = (int) ($_POST['loan_id'] ?? 0);

        $errors = [];
        $loan = null;

        if (!in_array($letterType, ['Completion Letter', 'Consolidation Letter'], true)) {
            $errors['letter_type'] = 'Select a letter type.';
        }

        if ($letterType === 'Completion Letter') {
            $loan = $this->loans->findForBorrower($loanId, $borrowerId);
            if (!$loan || $loan['loan_status'] !== 'Completed') {
                $errors['loan_id'] = 'Select one of your completed loans.';
            }
        } elseif ($letterType === 'Consolidation Letter' && $scope === 'one') {
            $loan = $this->loans->findForBorrower($loanId, $borrowerId);
            if (!$loan) {
                $errors['loan_id'] = 'Select which loan to consolidate.';
            }
        }

        if (!empty($errors)) {
            $this->view('portal/letters/create', [
                'title' => 'Request a Letter',
                'loans' => $this->loans->forBorrower($borrowerId),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $template = $this->documentTemplates->findByType($letterType);
        $title = $letterType === 'Completion Letter'
            ? 'Completion Letter - ' . $loan['loan_no']
            : ($scope === 'all' ? 'Consolidation Letter - All Loans' : 'Consolidation Letter - ' . $loan['loan_no']);

        $documentId = $this->generatedDocuments->create([
            'template_id' => $template['id'] ?? null,
            'document_no' => generate_reference('DOC'),
            'document_title' => $title,
            'borrower_id' => $borrowerId,
            'loan_id' => $loan['id'] ?? null,
            'source_module' => 'Borrower Portal',
            'status' => 'Draft',
        ]);

        Audit::log('Create', 'Documents', 'Borrower portal requested "' . $title . '" (#' . $documentId . ')');
        Session::flash('success', 'Your letter request has been submitted. Our team will prepare it and it will appear here once ready.');
        $this->redirect('/portal/letters');
    }

    public function letterDownload(string $id): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $document = $this->generatedDocuments->findForBorrower((int) $id, $borrowerId);
        if (!$document || $document['status'] !== 'Generated' || !$document['file_path']) {
            Session::flash('error', 'This letter is not ready yet.');
            $this->redirect('/portal/letters');
        }

        $this->streamStorageFile($document['file_path'], '/portal/letters');
    }

    // ---------------------------------------------------------------
    // Refund Claims
    // ---------------------------------------------------------------

    public function refundClaims(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/refund_claims/index', [
            'title' => 'My Refund Claims',
            'claims' => $this->refundClaims->forBorrower($borrowerId),
        ]);
    }

    public function refundClaimCreate(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        $this->view('portal/refund_claims/create', [
            'title' => 'Submit a Refund Claim',
            'loans' => $this->loans->forBorrower($borrowerId),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function refundClaimStore(): void
    {
        PortalAuth::requireLogin();
        $borrowerId = (int) PortalAuth::user()['borrower_id'];

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/portal/refund-claims/create');
        }

        $claimAmount = (float) ($_POST['claim_amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $loanId = (int) ($_POST['loan_id'] ?? 0);

        $errors = [];
        if ($claimAmount <= 0) {
            $errors['claim_amount'] = 'Enter a valid claim amount.';
        }
        if ($reason === '') {
            $errors['reason'] = 'Tell us the reason for this claim.';
        }

        $loan = null;
        if ($loanId > 0) {
            $loan = $this->loans->findForBorrower($loanId, $borrowerId);
            if (!$loan) {
                $errors['loan_id'] = 'Select a valid loan or leave blank.';
            }
        }

        $documentErrors = $this->validateDocumentUploads($_FILES['documents'] ?? []);
        $errors = array_merge($errors, $documentErrors);

        if (!empty($errors)) {
            $this->view('portal/refund_claims/create', [
                'title' => 'Submit a Refund Claim',
                'loans' => $this->loans->forBorrower($borrowerId),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $borrower = $this->borrowers->find($borrowerId);
        $claimNo = generate_reference('RFC');

        $claimId = $this->refundClaims->create([
            'borrower_id' => $borrowerId,
            'loan_id' => $loan['id'] ?? null,
            'branch_id' => $borrower['branch_id'],
            'claim_no' => $claimNo,
            'claim_date' => date('Y-m-d'),
            'claim_type' => $_POST['claim_type'] ?: 'Overpayment',
            'claim_amount' => $claimAmount,
            'reason' => $reason,
            'bank_name' => trim($_POST['bank_name'] ?? '') ?: null,
            'account_name' => trim($_POST['account_name'] ?? '') ?: null,
            'account_number' => trim($_POST['account_number'] ?? '') ?: null,
            'branch_code' => trim($_POST['branch_code'] ?? '') ?: null,
            'status' => 'Pending',
            'requested_by' => $borrowerId,
        ]);

        $this->storeRefundClaimDocuments($claimId, $claimNo, $_FILES['documents'] ?? []);

        Audit::log('Create', 'Refund Claims', 'Borrower portal submitted refund claim #' . $claimId . ' by borrower #' . $borrowerId);
        Session::flash('success', 'Your refund claim has been submitted for review.');
        $this->redirect('/portal/refund-claims');
    }

    // ---------------------------------------------------------------
    // Shared file-upload helpers
    // ---------------------------------------------------------------

    private function validateDocumentUploads(array $files): array
    {
        $errors = [];

        foreach ($files['error'] ?? [] as $key => $error) {
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors["documents.$key"] = 'Upload failed. Please try again.';
                continue;
            }

            $size = $files['size'][$key] ?? 0;
            $name = $files['name'][$key] ?? '';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($size > self::MAX_DOCUMENT_SIZE) {
                $errors["documents.$key"] = 'File is too large (max 5MB).';
            } elseif (!in_array($ext, self::ALLOWED_DOCUMENT_EXTENSIONS, true)) {
                $errors["documents.$key"] = 'Only PDF, JPG and PNG files are allowed.';
            }
        }

        return $errors;
    }

    private function storeLoanRequestDocuments(int $requestId, string $requestNo, array $files): void
    {
        if (empty($files['error'])) {
            return;
        }

        $requirements = array_column($this->uploadRequirements->forBorrowers(), null, 'id');
        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $requestNo);
        $targetDir = STORAGE_PATH . '/uploads/loan_requests/' . $safeFolder;

        foreach ($files['error'] as $key => $error) {
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }
            $requirement = $requirements[$key] ?? null;

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $ext = strtolower(pathinfo($files['name'][$key], PATHINFO_EXTENSION));
            $storedName = uniqid('doc_', true) . '.' . $ext;

            if (!move_uploaded_file($files['tmp_name'][$key], $targetDir . '/' . $storedName)) {
                continue;
            }

            $this->loanRequests->attachDocument([
                'loan_request_id' => $requestId,
                'document_type' => $requirement['document_type'] ?? 'Other',
                'document_name' => $requirement['requirement_name'] ?? 'Supporting Document',
                'file_path' => 'uploads/loan_requests/' . $safeFolder . '/' . $storedName,
            ]);
        }
    }

    private function storeRefundClaimDocuments(int $claimId, string $claimNo, array $files): void
    {
        if (empty($files['error'])) {
            return;
        }

        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $claimNo);
        $targetDir = STORAGE_PATH . '/uploads/refund_claims/' . $safeFolder;

        foreach ($files['error'] as $key => $error) {
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $originalName = $files['name'][$key];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $storedName = uniqid('doc_', true) . '.' . $ext;

            if (!move_uploaded_file($files['tmp_name'][$key], $targetDir . '/' . $storedName)) {
                continue;
            }

            $this->refundClaims->attachDocument([
                'refund_claim_id' => $claimId,
                'document_type' => 'Supporting Document',
                'document_name' => $originalName,
                'file_path' => 'uploads/refund_claims/' . $safeFolder . '/' . $storedName,
            ]);
        }
    }

    private function streamStorageFile(string $relativePath, string $fallbackRedirect): void
    {
        $fullPath = STORAGE_PATH . '/' . $relativePath;
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect($fallbackRedirect);
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
}
