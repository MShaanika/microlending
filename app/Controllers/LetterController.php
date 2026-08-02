<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Loan;
use App\Services\DocumentGenerationService;

class LetterController extends Controller
{
    private GeneratedDocument $documents;
    private Loan $loans;
    private Borrower $borrowers;
    private DocumentTemplate $templates;

    private const ALLOWED_EXTENSIONS = ['pdf'];
    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct()
    {
        $this->documents = new GeneratedDocument();
        $this->loans = new Loan();
        $this->borrowers = new Borrower();
        $this->templates = new DocumentTemplate();
    }

    /** Hard scope -- null means unrestricted (Super Admin only). */
    private function scopeBranchId(): ?int
    {
        return Auth::isSuperAdmin() ? null : (Auth::branchId() ?? 0);
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    /** Redirects away (404-style) if the letter's borrower belongs to another branch and the viewer isn't Super Admin. */
    private function assertBranchAccess(?array $document): void
    {
        if (!$document || Auth::isSuperAdmin() || empty($document['borrower_id'])) {
            return;
        }
        if ((int) ($document['borrower_branch_id'] ?? 0) !== (int) Auth::branchId()) {
            Session::flash('error', 'Letter request not found.');
            $this->redirect('/letters');
        }
    }

    public function index(): void
    {
        Auth::authorize('documents.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $branchId = $this->indexBranchId();

        $this->view('letters/index', [
            'title' => 'Borrower Letter Requests',
            'documents' => $this->documents->paginated($status, 100, $branchId),
            'status' => $status,
        ]);
    }

    /**
     * Staff-initiated letter, for when the borrower hasn't (or can't)
     * request one via the portal themselves -- without this, Completion
     * and Consolidation letters could only ever be started by a borrower.
     */
    public function create(): void
    {
        Auth::authorize('documents.generate');
        $branchId = $this->scopeBranchId();

        $this->view('letters/create', [
            'title' => 'Generate Letter',
            'loans' => $this->loans->paginated('', '', 500, $branchId),
            'borrowers' => $this->borrowers->paginated('', '', 500, $branchId),
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('documents.generate');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/letters/create');
            return;
        }

        $letterType = $_POST['letter_type'] ?? '';
        $scope = $_POST['consolidation_scope'] ?? 'one';
        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $branchId = $this->scopeBranchId();

        $errors = [];
        $loan = null;

        if (!in_array($letterType, ['Completion Letter', 'Consolidation Letter'], true)) {
            $errors['letter_type'] = 'Select a letter type.';
        }

        if ($letterType === 'Completion Letter') {
            $loan = $loanId ? $this->loans->find($loanId) : null;
            if (!$loan || $loan['loan_status'] !== 'Completed' || ($branchId !== null && (int) $loan['branch_id'] !== $branchId)) {
                $errors['loan_id'] = 'Select one of the borrower\'s completed loans.';
            }
        } elseif ($letterType === 'Consolidation Letter' && $scope === 'one') {
            $loan = $loanId ? $this->loans->find($loanId) : null;
            if (!$loan || ($branchId !== null && (int) $loan['branch_id'] !== $branchId)) {
                $errors['loan_id'] = 'Select which loan to consolidate.';
            }
        } elseif ($letterType === 'Consolidation Letter' && $scope === 'all') {
            $borrower = $borrowerId ? $this->borrowers->find($borrowerId) : null;
            if (!$borrower || ($branchId !== null && (int) $borrower['branch_id'] !== $branchId)) {
                $errors['borrower_id'] = 'Select a borrower.';
            }
        }

        if (!empty($errors)) {
            $this->view('letters/create', [
                'title' => 'Generate Letter',
                'loans' => $this->loans->paginated('', '', 500, $branchId),
                'borrowers' => $this->borrowers->paginated('', '', 500, $branchId),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        if ($letterType === 'Completion Letter') {
            $template = $this->templates->findByCode('COMPLETION_LETTER');
        } else {
            $template = $this->templates->findByCode($scope === 'all' ? 'CONSOLIDATION_ALL_LOANS' : 'CONSOLIDATION_ONE_LOAN');
        }

        $title = $letterType === 'Completion Letter'
            ? 'Completion Letter - ' . $loan['loan_no']
            : ($scope === 'all' ? 'Consolidation Letter - All Loans' : 'Consolidation Letter - ' . $loan['loan_no']);

        $documentId = $this->documents->create([
            'template_id' => $template['id'] ?? null,
            'document_no' => generate_reference('DOC'),
            'document_title' => $title,
            'borrower_id' => $loan['borrower_id'] ?? $borrowerId,
            'loan_id' => $loan['id'] ?? null,
            'source_module' => 'Staff',
            'status' => 'Draft',
        ]);

        Audit::log('Create', 'Documents', 'Staff created "' . $title . '" (#' . $documentId . ')');

        // Generate immediately rather than leaving it as another Draft to
        // come back to -- staff asked for this letter right now.
        $document = $this->documents->find($documentId);
        try {
            $filePath = DocumentGenerationService::generate($document);
            $this->documents->markFulfilled($documentId, $filePath, Auth::user()['id'] ?? null);
            Session::flash('success', 'Letter generated. Download it below, or the borrower can get it from their portal.');
        } catch (\RuntimeException $e) {
            Session::flash('error', 'Letter request created, but could not auto-generate: ' . $e->getMessage() . ' Use "Upload Prepared Letter" below instead.');
        }

        $this->redirect('/letters');
    }

    /**
     * Staff prepares the actual letter (real letterhead/wording) outside
     * the system and uploads the final PDF here to fulfil the request.
     */
    public function fulfill(string $id): void
    {
        Auth::authorize('documents.send');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/letters');
        }

        $document = $this->documents->find($id);
        if (!$document) {
            Session::flash('error', 'Letter request not found.');
            $this->redirect('/letters');
        }
        $this->assertBranchAccess($document);

        $file = $_FILES['letter_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Please attach the prepared PDF letter.');
            $this->redirect('/letters');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['size'] > self::MAX_SIZE || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            Session::flash('error', 'The letter must be a PDF under 10MB.');
            $this->redirect('/letters');
        }

        $safeFolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $document['document_no']);
        $targetDir = STORAGE_PATH . '/uploads/letters/' . $safeFolder;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $storedName = uniqid('letter_', true) . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $targetDir . '/' . $storedName)) {
            Session::flash('error', 'Could not save the uploaded file.');
            $this->redirect('/letters');
        }

        $this->documents->markFulfilled($id, 'uploads/letters/' . $safeFolder . '/' . $storedName, Auth::user()['id'] ?? null);

        Audit::log('Fulfill', 'Documents', 'Uploaded prepared letter for request #' . $id);
        Session::flash('success', 'Letter uploaded. The borrower can now download it from their portal.');
        $this->redirect('/letters');
    }

    /**
     * Auto-fill the request's template from the borrower/loan data instead
     * of staff preparing it by hand -- see DocumentGenerationService. Falls
     * back to the manual upload above if the template has no field mapping
     * configured yet, so this never blocks a request from being fulfilled.
     */
    public function generate(string $id): void
    {
        Auth::authorize('documents.generate');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/letters');
            return;
        }

        $document = $this->documents->find($id);
        if (!$document) {
            Session::flash('error', 'Letter request not found.');
            $this->redirect('/letters');
            return;
        }
        $this->assertBranchAccess($document);

        try {
            $filePath = DocumentGenerationService::generate($document);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/letters');
            return;
        }

        $this->documents->markFulfilled($id, $filePath, Auth::user()['id'] ?? null);

        Audit::log('Generate', 'Documents', 'Auto-generated letter for request #' . $id);
        Session::flash('success', 'Letter generated automatically. The borrower can now download it from their portal.');
        $this->redirect('/letters');
    }

    public function download(string $id): void
    {
        Auth::authorize('documents.view');
        $document = $this->documents->find((int) $id);

        if (!$document || !$document['file_path']) {
            Session::flash('error', 'This document is not ready yet.');
            $this->redirect('/letters');
            return;
        }
        $this->assertBranchAccess($document);

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/letters');
            return;
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
        $disposition = $ext === 'docx' ? 'attachment' : 'inline';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }
}
