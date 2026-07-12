<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\NotificationTemplate;

class NotificationTemplateController extends Controller
{
    private NotificationTemplate $templates;

    public const CHANNELS = ['SMS', 'Email', 'WhatsApp', 'Portal'];

    /** The merge fields NotificationMergeService actually resolves --
     *  shown to staff as a hint so they don't type a placeholder that will
     *  never fill in. */
    public const MERGE_FIELDS = [
        'borrower_full_name', 'application_no', 'amount_due', 'due_date',
        'arrears_amount', 'loan_no', 'claim_no', 'current_date',
    ];

    public function __construct()
    {
        $this->templates = new NotificationTemplate();
    }

    public function index(): void
    {
        Auth::authorize('notifications.templates');

        $this->view('notifications/templates/index', [
            'title' => 'Notification Templates',
            'templates' => $this->templates->allTemplates(),
            'mergeFields' => self::MERGE_FIELDS,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('notifications.templates');

        $this->view('notifications/templates/create', [
            'title' => 'New Notification Template',
            'channels' => self::CHANNELS,
            'mergeFields' => self::MERGE_FIELDS,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('notifications.templates');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/templates/create');
            return;
        }

        $code = strtoupper(trim($_POST['template_code'] ?? ''));
        $name = trim($_POST['template_name'] ?? '');
        $body = trim($_POST['message_body'] ?? '');
        $channel = $_POST['channel'] ?? '';
        $errors = [];

        if ($code === '') {
            $errors['template_code'] = 'Template code is required.';
        } elseif ($this->templates->codeExists($code)) {
            $errors['template_code'] = 'A template with this code already exists.';
        }
        if ($name === '') {
            $errors['template_name'] = 'Template name is required.';
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            $errors['channel'] = 'Select a channel.';
        }
        if ($body === '') {
            $errors['message_body'] = 'Message body is required.';
        }

        if (!empty($errors)) {
            $this->view('notifications/templates/create', [
                'title' => 'New Notification Template',
                'channels' => self::CHANNELS,
                'mergeFields' => self::MERGE_FIELDS,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->templates->create([
            'template_code' => $code,
            'template_name' => $name,
            'channel' => $channel,
            'subject' => trim($_POST['subject'] ?? '') ?: null,
            'message_body' => $body,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Notifications', 'Created notification template #' . $id . ' (' . $code . ')');
        Session::flash('success', 'Template created.');
        $this->redirect('/notifications/templates');
    }

    public function edit(string $id): void
    {
        Auth::authorize('notifications.templates');
        $template = $this->templates->find((int) $id);

        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/notifications/templates');
            return;
        }

        $this->view('notifications/templates/edit', [
            'title' => 'Edit ' . $template['template_name'],
            'template' => $template,
            'channels' => self::CHANNELS,
            'mergeFields' => self::MERGE_FIELDS,
            'old' => [],
            'errors' => [],
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('notifications.templates');
        $id = (int) $id;
        $template = $this->templates->find($id);

        if (!$template) {
            Session::flash('error', 'Template not found.');
            $this->redirect('/notifications/templates');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/notifications/templates/' . $id . '/edit');
            return;
        }

        $code = strtoupper(trim($_POST['template_code'] ?? ''));
        $name = trim($_POST['template_name'] ?? '');
        $body = trim($_POST['message_body'] ?? '');
        $channel = $_POST['channel'] ?? '';
        $errors = [];

        if ($code === '') {
            $errors['template_code'] = 'Template code is required.';
        } elseif ($this->templates->codeExists($code, $id)) {
            $errors['template_code'] = 'A template with this code already exists.';
        }
        if ($name === '') {
            $errors['template_name'] = 'Template name is required.';
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            $errors['channel'] = 'Select a channel.';
        }
        if ($body === '') {
            $errors['message_body'] = 'Message body is required.';
        }

        if (!empty($errors)) {
            $this->view('notifications/templates/edit', [
                'title' => 'Edit ' . $template['template_name'],
                'template' => $template,
                'channels' => self::CHANNELS,
                'mergeFields' => self::MERGE_FIELDS,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $this->templates->updateRecord($id, [
            'template_code' => $code,
            'template_name' => $name,
            'channel' => $channel,
            'subject' => trim($_POST['subject'] ?? '') ?: null,
            'message_body' => $body,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ]);

        Audit::log('Update', 'Notifications', 'Updated notification template #' . $id);
        Session::flash('success', 'Template updated.');
        $this->redirect('/notifications/templates');
    }
}
