<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentChecklistItem;
use App\Models\RecruitmentOnboardingChecklist;

class RecruitmentOnboardingChecklistController extends Controller
{
    private RecruitmentOnboardingChecklist $checklists;
    private RecruitmentChecklistItem $items;

    public function __construct()
    {
        $this->checklists = new RecruitmentOnboardingChecklist();
        $this->items = new RecruitmentChecklistItem();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $this->view('recruitment/onboarding-checklists/index', [
            'title' => 'Onboarding Checklists',
            'checklists' => $this->checklists->allChecklists(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/onboarding-checklists/create', [
            'title' => 'Add Onboarding Checklist',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/onboarding-checklists/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/onboarding-checklists/create', [
                'title' => 'Add Onboarding Checklist',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->checklists->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created onboarding checklist #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Checklist created.');
        $this->redirect('/recruitment/onboarding-checklists/' . $id);
    }

    public function show(int $id): void
    {
        Auth::authorize('recruitment.view');
        $checklist = $this->checklists->find($id);
        if (!$checklist) {
            Session::flash('error', 'Checklist not found.');
            $this->redirect('/recruitment/onboarding-checklists');
            return;
        }
        $this->view('recruitment/onboarding-checklists/show', [
            'title' => $checklist['name'],
            'checklist' => $checklist,
            'items' => $this->items->forChecklist($id),
        ]);
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $checklist = $this->checklists->find($id);
        if (!$checklist) {
            Session::flash('error', 'Checklist not found.');
            $this->redirect('/recruitment/onboarding-checklists');
            return;
        }
        $this->view('recruitment/onboarding-checklists/edit', [
            'title' => 'Edit Onboarding Checklist',
            'checklist' => $checklist,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/onboarding-checklists/' . $id . '/edit');
            return;
        }

        $checklist = $this->checklists->find($id);
        if (!$checklist) {
            Session::flash('error', 'Checklist not found.');
            $this->redirect('/recruitment/onboarding-checklists');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/onboarding-checklists/edit', [
                'title' => 'Edit Onboarding Checklist',
                'checklist' => array_merge($checklist, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->checklists->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated onboarding checklist #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Checklist updated.');
        $this->redirect('/recruitment/onboarding-checklists');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/onboarding-checklists');
            return;
        }

        $this->checklists->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted onboarding checklist #' . $id);
        Session::flash('success', 'Checklist deleted.');
        $this->redirect('/recruitment/onboarding-checklists');
    }

    public function addItem(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/onboarding-checklists/' . $id);
            return;
        }

        $taskName = trim($_POST['task_name'] ?? '');
        if ($taskName === '') {
            Session::flash('error', 'Task name is required.');
            $this->redirect('/recruitment/onboarding-checklists/' . $id);
            return;
        }

        $this->items->create([
            'checklist_id' => $id,
            'task_name' => $taskName,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'category' => trim($_POST['category'] ?? '') ?: null,
            'assigned_to_role' => trim($_POST['assigned_to_role'] ?? '') ?: null,
            'due_day' => ($_POST['due_day'] ?? '') !== '' ? (int) $_POST['due_day'] : null,
            'is_required' => !empty($_POST['is_required']) ? 1 : 0,
            'status' => 'Active',
        ]);

        Session::flash('success', 'Checklist item added.');
        $this->redirect('/recruitment/onboarding-checklists/' . $id);
    }

    public function deleteItem(int $id, int $itemId): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/onboarding-checklists/' . $id);
            return;
        }

        $this->items->delete($itemId);
        Session::flash('success', 'Checklist item deleted.');
        $this->redirect('/recruitment/onboarding-checklists/' . $id);
    }

    private function validate(array $post): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
            'is_default' => !empty($post['is_default']) ? 1 : 0,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
