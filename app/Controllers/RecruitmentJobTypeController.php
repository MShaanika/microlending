<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RecruitmentJobType;

class RecruitmentJobTypeController extends Controller
{
    private RecruitmentJobType $types;

    public function __construct()
    {
        $this->types = new RecruitmentJobType();
    }

    public function index(): void
    {
        Auth::authorize('recruitment.view');
        $this->view('recruitment/job-types/index', [
            'title' => 'Job Types',
            'types' => $this->types->allTypes(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('recruitment.manage');
        $this->view('recruitment/job-types/create', [
            'title' => 'Add Job Type',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/job-types/create', [
                'title' => 'Add Job Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Recruitment', 'Created job type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Job type created.');
        $this->redirect('/recruitment/job-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('recruitment.manage');
        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Job type not found.');
            $this->redirect('/recruitment/job-types');
            return;
        }
        $this->view('recruitment/job-types/edit', [
            'title' => 'Edit Job Type',
            'type' => $type,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Job type not found.');
            $this->redirect('/recruitment/job-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('recruitment/job-types/edit', [
                'title' => 'Edit Job Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'Recruitment', 'Updated job type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Job type updated.');
        $this->redirect('/recruitment/job-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('recruitment.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/recruitment/job-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This job type has job postings assigned to it and cannot be deleted.');
            $this->redirect('/recruitment/job-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'Recruitment', 'Deleted job type #' . $id);
        Session::flash('success', 'Job type deleted.');
        $this->redirect('/recruitment/job-types');
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
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
