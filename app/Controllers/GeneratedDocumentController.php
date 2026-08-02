<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Session;
use App\Models\GeneratedDocument;

class GeneratedDocumentController extends Controller
{
    private GeneratedDocument $documents;

    public function __construct()
    {
        $this->documents = new GeneratedDocument();
    }

    /** Same as scopeBranchId(), but Super Admin can additionally narrow via ?branch_id=, defaulting to all branches. */
    private function indexBranchId(): ?int
    {
        if (!Auth::isSuperAdmin()) {
            return Auth::branchId() ?? 0;
        }
        return !empty($_GET['branch_id']) ? (int) $_GET['branch_id'] : null;
    }

    /** Redirects away (404-style) if the document's borrower belongs to another branch and the viewer isn't Super Admin.
     *  A document with no linked borrower (application-stage) is always visible. */
    private function assertBranchAccess(?array $document): void
    {
        if (!$document || Auth::isSuperAdmin() || empty($document['borrower_id'])) {
            return;
        }
        if ((int) ($document['borrower_branch_id'] ?? 0) !== (int) Auth::branchId()) {
            Session::flash('error', 'Document not found.');
            $this->redirect('/generated-documents');
        }
    }

    public function index(): void
    {
        Auth::authorize('documents.view');

        $sourceModule = trim((string) ($_GET['source_module'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $branchId = $this->indexBranchId();

        $this->view('generated_documents/index', [
            'title' => 'Generated Documents',
            'documents' => $this->documents->paginatedAll($sourceModule, $status, $search, 200, $branchId),
            'sourceModules' => $this->documents->sourceModules(),
            'sourceModule' => $sourceModule,
            'status' => $status,
            'search' => $search,
            'branches' => Auth::isSuperAdmin() ? (new \App\Models\Branch())->all() : [],
            'selectedBranchId' => $branchId,
        ]);
    }

    /**
     * Same mime-by-extension / inline-for-PDF / attachment-for-DOCX
     * streaming as LetterController::download() -- deliberately duplicated
     * rather than shared, matching this app's convention of small
     * duplication over premature abstraction.
     */
    public function download(string $id): void
    {
        Auth::authorize('documents.view');
        $document = $this->documents->find((int) $id);

        if (!$document || !$document['file_path']) {
            Session::flash('error', 'This document is not ready yet.');
            $this->redirect('/generated-documents');
            return;
        }
        $this->assertBranchAccess($document);

        $fullPath = STORAGE_PATH . '/' . $document['file_path'];
        if (!is_file($fullPath)) {
            Session::flash('error', 'File is missing from storage.');
            $this->redirect('/generated-documents');
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
