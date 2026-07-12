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

    public function index(): void
    {
        Auth::authorize('documents.view');

        $sourceModule = trim((string) ($_GET['source_module'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));

        $this->view('generated_documents/index', [
            'title' => 'Generated Documents',
            'documents' => $this->documents->paginatedAll($sourceModule, $status, $search),
            'sourceModules' => $this->documents->sourceModules(),
            'sourceModule' => $sourceModule,
            'status' => $status,
            'search' => $search,
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
