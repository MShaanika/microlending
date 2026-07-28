<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAllowanceType;

class HrmAllowanceTypeController extends Controller
{
    private HrmAllowanceType $types;

    public function __construct()
    {
        $this->types = new HrmAllowanceType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $this->view('hrm/allowance-types/index', [
            'title' => 'Allowance Types',
            'types' => $this->types->allTypes(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/allowance-types/create', [
            'title' => 'Add Allowance Type',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/allowance-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/allowance-types/create', [
                'title' => 'Add Allowance Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Created allowance type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Allowance type created.');
        $this->redirect('/hrm/allowance-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Allowance type not found.');
            $this->redirect('/hrm/allowance-types');
            return;
        }
        $this->view('hrm/allowance-types/edit', [
            'title' => 'Edit Allowance Type',
            'type' => $type,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/allowance-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Allowance type not found.');
            $this->redirect('/hrm/allowance-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/allowance-types/edit', [
                'title' => 'Edit Allowance Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated allowance type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Allowance type updated.');
        $this->redirect('/hrm/allowance-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/allowance-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This allowance type is assigned to employees and cannot be deleted.');
            $this->redirect('/hrm/allowance-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted allowance type #' . $id);
        Session::flash('success', 'Allowance type deleted.');
        $this->redirect('/hrm/allowance-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->types->nameExists($name, $excludeId)) {
            $errors['name'] = 'An allowance type with this name already exists.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
