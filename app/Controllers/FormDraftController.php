<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\DraftDocument;
use App\Models\FormDraft;
use App\Models\SystemSetting;

/**
 * Self-service draft autosave/recovery, mirroring the existing /my/...
 * per-user-scoped self-service pattern (AgentSelfServiceController's
 * /my/referrals + allForAgent()) -- every draft is scoped to its own
 * creator (user_id = ?), never branch-scoped, since a draft belongs to
 * whoever started it regardless of branch.
 */
class FormDraftController extends Controller
{
    private FormDraft $drafts;
    private DraftDocument $documents;
    private SystemSetting $settings;

    public function __construct()
    {
        $this->drafts = new FormDraft();
        $this->documents = new DraftDocument();
        $this->settings = new SystemSetting();
    }

    /** Where "Continue" sends the user back to, per workflow -- the only two draft-enabled forms today. */
    private const RESUME_ROUTES = [
        'borrowers.create' => '/borrowers/create',
        'agent.referral.create' => '/my/referrals/create',
    ];

    public function index(): void
    {
        Auth::requireLogin();
        $userId = (int) (Auth::user()['id'] ?? 0);
        $drafts = $this->drafts->allForUser($userId);
        foreach ($drafts as &$draft) {
            $base = self::RESUME_ROUTES[$draft['workflow_key']] ?? null;
            $draft['resume_url'] = $base ? $base . '?draft=' . urlencode($draft['draft_uuid']) : null;
        }
        unset($draft);

        $this->view('my/drafts/index', [
            'title' => 'My Drafts',
            'drafts' => $drafts,
        ]);
    }

    /** Create (first autosave) or update (subsequent autosaves) a draft. Called frequently -- kept lightweight, no audit entry per tick. */
    public function save(): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->jsonCsrfFailure();
            return;
        }
        $userId = (int) (Auth::user()['id'] ?? 0);
        if (!$userId) {
            $this->jsonErrors(['_general' => 'Session expired. Please log in again.'], 401);
            return;
        }

        $uuid = trim((string) ($_POST['draft_uuid'] ?? ''));
        $module = trim((string) ($_POST['module'] ?? ''));
        $workflowKey = trim((string) ($_POST['workflow_key'] ?? ''));
        $formData = (string) ($_POST['form_data'] ?? '{}');
        $currentStep = trim((string) ($_POST['current_step'] ?? '')) ?: null;

        if ($uuid === '' || $module === '' || $workflowKey === '') {
            $this->jsonErrors(['_general' => 'Missing draft identifiers.'], 422);
            return;
        }

        $retentionDays = max(1, (int) $this->settings->get('draft_retention_days', '14'));
        $wasNew = !$this->drafts->findByUuid($uuid, $userId);

        if ($wasNew) {
            $this->drafts->create([
                'draft_uuid' => $uuid,
                'module' => $module,
                'workflow_key' => $workflowKey,
                'user_id' => $userId,
                'form_data' => $formData,
                'current_step' => $currentStep,
                'status' => 'DRAFT',
                'device_info' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'last_autosaved_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + $retentionDays * 86400),
            ]);
            Audit::log('Create', 'Drafts', 'Draft started for ' . $workflowKey, [], $uuid);
        } else {
            $this->drafts->saveProgress($uuid, $userId, $formData, $currentStep, $retentionDays);
        }

        $this->jsonSuccess('Draft saved.', null, ['saved_at' => date('H:i')]);
    }

    /**
     * Lightweight check for the recovery prompt ("You have an unfinished
     * draft from ...") -- returns just enough to render that prompt, not
     * the full form data, so a draft-enabled form's page load doesn't pay
     * for fetching a potentially large form_data blob it may not need.
     */
    public function latest(): void
    {
        Auth::requireLogin();
        $userId = (int) (Auth::user()['id'] ?? 0);
        $workflowKey = trim((string) ($_GET['workflow_key'] ?? ''));
        $draft = $workflowKey !== '' ? $this->drafts->latestForWorkflow($userId, $workflowKey) : null;

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'draft' => $draft ? [
                'uuid' => $draft['draft_uuid'],
                'last_autosaved_at' => $draft['last_autosaved_at'],
                'created_at' => $draft['created_at'],
            ] : null,
        ]);
        exit;
    }

    /** Fetches one draft's stored data + staged documents for client-side resume. */
    public function show(string $uuid): void
    {
        Auth::requireLogin();
        $userId = (int) (Auth::user()['id'] ?? 0);
        $draft = $this->drafts->findByUuid($uuid, $userId);
        if (!$draft) {
            $this->jsonErrors(['_general' => 'Draft not found.'], 404);
            return;
        }

        Audit::log('Resume', 'Drafts', 'Draft resumed for ' . $draft['workflow_key'], [], $uuid);

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'draft' => [
                'uuid' => $draft['draft_uuid'],
                'module' => $draft['module'],
                'workflow_key' => $draft['workflow_key'],
                'form_data' => json_decode((string) $draft['form_data'], true),
                'current_step' => $draft['current_step'],
                'last_autosaved_at' => $draft['last_autosaved_at'],
            ],
            'documents' => $this->documents->forDraft($uuid),
        ]);
        exit;
    }

    public function discard(string $uuid): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
                return;
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/my/drafts');
            return;
        }

        $userId = (int) (Auth::user()['id'] ?? 0);
        $draft = $userId ? $this->drafts->findByUuid($uuid, $userId) : null;
        if ($draft) {
            $this->removeStagedFiles($uuid);
            $this->documents->deleteForDraft($uuid);
            $this->drafts->discard($uuid, $userId);
            Audit::log('Discard', 'Drafts', 'Draft discarded for ' . $draft['workflow_key'], [], $uuid);
        }

        if ($this->isAjax()) {
            $this->jsonSuccess('Draft discarded.');
            return;
        }
        Session::flash('success', 'Draft discarded.');
        $this->redirect('/my/drafts');
    }

    /** Stages one uploaded file against a draft -- moved to the real storage location only once the parent record is actually created (see BorrowerController). */
    public function uploadDocument(string $uuid): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->jsonCsrfFailure();
            return;
        }
        $userId = (int) (Auth::user()['id'] ?? 0);
        $draft = $userId ? $this->drafts->findByUuid($uuid, $userId) : null;
        if (!$draft) {
            $this->jsonErrors(['_general' => 'Draft not found.'], 404);
            return;
        }

        $fieldName = trim((string) ($_POST['field_name'] ?? ''));
        if ($fieldName === '' || empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->jsonErrors(['_general' => 'No file received.'], 422);
            return;
        }

        $ext = strtolower((string) pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'csv'], true)) {
            $this->jsonErrors(['_general' => 'Unsupported file type. Allowed: PDF, JPG, PNG, CSV.'], 422);
            return;
        }
        if ((int) $_FILES['file']['size'] > 5 * 1024 * 1024) {
            $this->jsonErrors(['_general' => 'File exceeds the 5MB limit.'], 422);
            return;
        }

        $targetDir = STORAGE_PATH . '/uploads/_drafts/' . $uuid;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $storedName = uniqid('draft_', true) . '.' . $ext;
        move_uploaded_file($_FILES['file']['tmp_name'], $targetDir . '/' . $storedName);

        $docId = $this->documents->create([
            'draft_uuid' => $uuid,
            'field_name' => $fieldName,
            'original_name' => $_FILES['file']['name'],
            'stored_path' => $storedName,
            'size_bytes' => (int) $_FILES['file']['size'],
        ]);

        $this->jsonSuccess('File uploaded.', null, [
            'document' => ['id' => $docId, 'field_name' => $fieldName, 'original_name' => $_FILES['file']['name']],
        ]);
    }

    public function deleteDocument(string $uuid, string $docId): void
    {
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            $this->jsonCsrfFailure();
            return;
        }
        $userId = (int) (Auth::user()['id'] ?? 0);
        $draft = $userId ? $this->drafts->findByUuid($uuid, $userId) : null;
        if (!$draft) {
            $this->jsonErrors(['_general' => 'Draft not found.'], 404);
            return;
        }

        $doc = $this->documents->find((int) $docId, $uuid);
        if ($doc) {
            $path = STORAGE_PATH . '/uploads/_drafts/' . $uuid . '/' . $doc['stored_path'];
            if (is_file($path)) {
                unlink($path);
            }
            $this->documents->delete((int) $docId, $uuid);
        }
        $this->jsonSuccess('Document removed.');
    }

    private function removeStagedFiles(string $uuid): void
    {
        $dir = STORAGE_PATH . '/uploads/_drafts/' . $uuid;
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($dir . '/' . $file);
            }
        }
        @rmdir($dir);
    }
}
