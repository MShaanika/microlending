<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\GeneratedDocument;

class LetterController extends Controller
{
    private GeneratedDocument $documents;

    private const ALLOWED_EXTENSIONS = ['pdf'];
    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct()
    {
        $this->documents = new GeneratedDocument();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $status = trim((string) ($_GET['status'] ?? ''));

        $this->view('letters/index', [
            'title' => 'Borrower Letter Requests',
            'documents' => $this->documents->paginated($status),
            'status' => $status,
        ]);
    }

    /**
     * Staff prepares the actual letter (real letterhead/wording) outside
     * the system and uploads the final PDF here to fulfil the request.
     */
    public function fulfill(string $id): void
    {
        Auth::requireLogin();
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
}
