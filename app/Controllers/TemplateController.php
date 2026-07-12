<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateCategory;

class TemplateController extends Controller
{
    private DocumentTemplate $templates;
    private DocumentTemplateCategory $categories;

    private const ALLOWED_EXTENSIONS = ['docx'];
    private const MAX_SIZE = 10 * 1024 * 1024; // 10MB

    public const TEMPLATE_TYPES = [
        'Completion Letter', 'Consolidation Letter', 'Refund Claim Form', 'Refund Claim Letter',
        'Debit Order Cancellation Letter', 'Loan Reschedule Letter', 'Statement of Account',
        'Agreement', 'Notice', 'Other',
    ];

    /** The only source_table values DocumentFieldResolver actually resolves
     *  -- constraining the field-mapping dropdown to these keeps staff from
     *  configuring a mapping that silently resolves to null. */
    public const FIELD_SOURCES = [
        'borrowers', 'loans', 'refund_claims', 'loan_applications',
        'loan_reschedules', 'debit_order_cancellations', 'computed',
    ];

    public function __construct()
    {
        $this->templates = new DocumentTemplate();
        $this->categories = new DocumentTemplateCategory();
    }

    public function index(): void
    {
        Auth::authorize('documents.templates');

        $templates = $this->templates->allTemplates();
        foreach ($templates as &$t) {
            $t['file_exists'] = is_file(STORAGE_PATH . '/' . $t['file_path']);
        }

        $this->view('templates/index', [
            'title' => 'Document Templates',
            'templates' => $templates,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('documents.templates');

        $this->view('templates/create', [
            'title' => 'New Document Template',
            'categories' => $this->categories->allCategories(true),
            'templateTypes' => self::TEMPLATE_TYPES,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('documents.templates');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/templates/create');
            return;
        }

        $code = strtoupper(trim($_POST['template_code'] ?? ''));
        $name = trim($_POST['template_name'] ?? '');
        $errors = [];

        if ($code === '') {
            $errors['template_code'] = 'Template code is required.';
        } elseif ($this->templates->codeExists($code)) {
            $errors['template_code'] = 'A template with this code already exists.';
        }
        if ($name === '') {
            $errors['template_name'] = 'Template name is required.';
        }
        if (!in_array($_POST['template_type'] ?? '', self::TEMPLATE_TYPES, true)) {
            $errors['template_type'] = 'Select a template type.';
        }

        $file = $_FILES['template_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['template_file'] = 'Upload the .docx template file.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['template_file'] = 'The file failed to upload. Please try again.';
        } else {
            $fileError = $this->validateFile($file);
            if ($fileError) {
                $errors['template_file'] = $fileError;
            }
        }

        if (!empty($errors)) {
            $this->view('templates/create', [
                'title' => 'New Document Template',
                'categories' => $this->categories->allCategories(true),
                'templateTypes' => self::TEMPLATE_TYPES,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $filePath = $this->storeTemplateFile($code, $file);

        $id = $this->templates->create([
            'category_id' => ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null,
            'template_code' => $code,
            'template_name' => $name,
            'template_type' => $_POST['template_type'],
            'file_type' => 'DOCX',
            'file_path' => $filePath,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Documents', 'Created document template #' . $id . ' (' . $code . ')');
        Session::flash('success', 'Template created. Add its field mappings next so it can be auto-generated.');
        $this->redirect('/templates/' . $id . '/fields');
    }

    public function edit(string $id): void
    {
        Auth::authorize('documents.templates');
        $template = $this->templates->find((int) $id);

        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/templates');
            return;
        }

        $this->view('templates/edit', [
            'title' => 'Edit ' . $template['template_name'],
            'template' => $template,
            'categories' => $this->categories->allCategories(true),
            'templateTypes' => self::TEMPLATE_TYPES,
            'fileExists' => is_file(STORAGE_PATH . '/' . $template['file_path']),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('documents.templates');
        $id = (int) $id;
        $template = $this->templates->find($id);

        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/templates');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/templates/' . $id . '/edit');
            return;
        }

        $code = strtoupper(trim($_POST['template_code'] ?? ''));
        $name = trim($_POST['template_name'] ?? '');
        $errors = [];

        if ($code === '') {
            $errors['template_code'] = 'Template code is required.';
        } elseif ($this->templates->codeExists($code, $id)) {
            $errors['template_code'] = 'A template with this code already exists.';
        }
        if ($name === '') {
            $errors['template_name'] = 'Template name is required.';
        }
        if (!in_array($_POST['template_type'] ?? '', self::TEMPLATE_TYPES, true)) {
            $errors['template_type'] = 'Select a template type.';
        }

        $file = $_FILES['template_file'] ?? null;
        $hasNewFile = $file && $file['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasNewFile) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors['template_file'] = 'The file failed to upload. Please try again.';
            } else {
                $fileError = $this->validateFile($file);
                if ($fileError) {
                    $errors['template_file'] = $fileError;
                }
            }
        }

        if (!empty($errors)) {
            $this->view('templates/edit', [
                'title' => 'Edit ' . $template['template_name'],
                'template' => $template,
                'categories' => $this->categories->allCategories(true),
                'templateTypes' => self::TEMPLATE_TYPES,
                'fileExists' => is_file(STORAGE_PATH . '/' . $template['file_path']),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data = [
            'category_id' => ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null,
            'template_code' => $code,
            'template_name' => $name,
            'template_type' => $_POST['template_type'],
            'description' => trim($_POST['description'] ?? '') ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($hasNewFile) {
            $data['file_path'] = $this->storeTemplateFile($code, $file);
        }

        $this->templates->updateRecord($id, $data);

        Audit::log('Update', 'Documents', 'Updated document template #' . $id . ($hasNewFile ? ' (replaced file)' : ''));
        Session::flash('success', 'Template updated.');
        $this->redirect('/templates');
    }

    public function fields(string $id): void
    {
        Auth::authorize('documents.templates');
        $template = $this->templates->find((int) $id);

        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/templates');
            return;
        }

        $this->view('templates/fields', [
            'title' => 'Fields - ' . $template['template_name'],
            'template' => $template,
            'fieldRows' => $this->templates->fields((int) $id),
            'fieldSources' => self::FIELD_SOURCES,
            'errors' => [],
        ]);
    }

    public function addField(string $id): void
    {
        Auth::authorize('documents.templates');
        $id = (int) $id;
        $template = $this->templates->find($id);

        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/templates');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/templates/' . $id . '/fields');
            return;
        }

        $key = trim($_POST['field_key'] ?? '');
        $sourceTable = trim($_POST['source_table'] ?? '');
        $errors = [];

        if ($key === '') {
            $errors['field_key'] = 'Field key (the placeholder name in the .docx) is required.';
        }
        if ($sourceTable !== '' && !in_array($sourceTable, self::FIELD_SOURCES, true)) {
            $errors['source_table'] = 'Unsupported source.';
        }

        if (!empty($errors)) {
            $this->view('templates/fields', [
                'title' => 'Fields - ' . $template['template_name'],
                'template' => $template,
                'fieldRows' => $this->templates->fields($id),
                'fieldSources' => self::FIELD_SOURCES,
                'errors' => $errors,
            ]);
            return;
        }

        $this->templates->addField([
            'template_id' => $id,
            'field_key' => $key,
            'field_label' => trim($_POST['field_label'] ?? '') ?: null,
            'source_table' => $sourceTable ?: null,
            'source_column' => trim($_POST['source_column'] ?? '') ?: null,
            'default_value' => trim($_POST['default_value'] ?? '') ?: null,
            'is_required' => isset($_POST['is_required']) ? 1 : 0,
        ]);

        Audit::log('Create', 'Documents', 'Added field "' . $key . '" to template #' . $id);
        Session::flash('success', 'Field mapping added.');
        $this->redirect('/templates/' . $id . '/fields');
    }

    public function deleteField(string $id, string $fieldId): void
    {
        Auth::authorize('documents.templates');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/templates/' . $id . '/fields');
            return;
        }

        $field = $this->templates->findField((int) $fieldId);
        if ($field && (int) $field['template_id'] === $id) {
            $this->templates->deleteField((int) $fieldId);
            Audit::log('Delete', 'Documents', 'Removed field "' . $field['field_key'] . '" from template #' . $id);
            Session::flash('success', 'Field mapping removed.');
        }

        $this->redirect('/templates/' . $id . '/fields');
    }

    private function validateFile(array $file): ?string
    {
        if ($file['size'] > self::MAX_SIZE) {
            return 'File is too large (max 10MB).';
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return 'Only .docx files are allowed.';
        }
        return null;
    }

    private function storeTemplateFile(string $code, array $file): string
    {
        $targetDir = STORAGE_PATH . '/document_templates';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', strtolower($code));
        $filename = $safeCode . '_' . date('YmdHis') . '.docx';
        move_uploaded_file($file['tmp_name'], $targetDir . '/' . $filename);

        return 'document_templates/' . $filename;
    }
}
