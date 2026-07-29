<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\StaffLoanType;

class StaffLoanTypeController extends Controller
{
    private StaffLoanType $types;

    public function __construct()
    {
        $this->types = new StaffLoanType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $this->view('hrm/staff-loan-types/index', [
            'title' => 'Staff Loan Types',
            'types' => $this->types->allTypes(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/staff-loan-types/create', [
            'title' => 'Add Staff Loan Type',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loan-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/staff-loan-types/create', [
                'title' => 'Add Staff Loan Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Created staff loan type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Staff loan type created.');
        $this->redirect('/hrm/staff-loan-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Staff loan type not found.');
            $this->redirect('/hrm/staff-loan-types');
            return;
        }
        $this->view('hrm/staff-loan-types/edit', [
            'title' => 'Edit Staff Loan Type',
            'type' => $type,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loan-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Staff loan type not found.');
            $this->redirect('/hrm/staff-loan-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/staff-loan-types/edit', [
                'title' => 'Edit Staff Loan Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated staff loan type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Staff loan type updated.');
        $this->redirect('/hrm/staff-loan-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/staff-loan-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This staff loan type is used by existing loans and cannot be deleted.');
            $this->redirect('/hrm/staff-loan-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted staff loan type #' . $id);
        Session::flash('success', 'Staff loan type deleted.');
        $this->redirect('/hrm/staff-loan-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->types->nameExists($name, $excludeId)) {
            $errors['name'] = 'A staff loan type with this name already exists.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
