<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\HrmDepartment;
use App\Models\HrmDesignation;

class HrmDesignationController extends Controller
{
    private HrmDesignation $designations;
    private HrmDepartment $departments;
    private Branch $branches;

    public function __construct()
    {
        $this->designations = new HrmDesignation();
        $this->departments = new HrmDepartment();
        $this->branches = new Branch();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $this->view('hrm/designations/index', [
            'title' => 'Designations',
            'designations' => $this->designations->allDesignations(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/designations/create', [
            'title' => 'Add Designation',
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/designations/create');
            return;
        }

        $name = trim($_POST['designation_name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['designation_name'] = 'Designation name is required.';
        } elseif ($this->designations->nameExists($name)) {
            $errors['designation_name'] = 'A designation with this name already exists.';
        }

        if (!empty($errors)) {
            $this->view('hrm/designations/create', [
                'title' => 'Add Designation',
                'branches' => $this->branches->all(),
                'departments' => $this->departments->allDepartments(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->designations->create([
            'designation_name' => $name,
            'branch_id' => !empty($_POST['branch_id']) ? (int) $_POST['branch_id'] : null,
            'department_id' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
            'is_active' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'HRM', 'Created designation #' . $id . ' - ' . $name);
        Session::flash('success', 'Designation created.');
        $this->redirect('/hrm/designations');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $designation = $this->designations->find($id);
        if (!$designation) {
            Session::flash('error', 'Designation not found.');
            $this->redirect('/hrm/designations');
            return;
        }
        $this->view('hrm/designations/edit', [
            'title' => 'Edit Designation',
            'designation' => $designation,
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/designations/' . $id . '/edit');
            return;
        }

        $designation = $this->designations->find($id);
        if (!$designation) {
            Session::flash('error', 'Designation not found.');
            $this->redirect('/hrm/designations');
            return;
        }

        $name = trim($_POST['designation_name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['designation_name'] = 'Designation name is required.';
        } elseif ($this->designations->nameExists($name, $id)) {
            $errors['designation_name'] = 'A designation with this name already exists.';
        }

        if (!empty($errors)) {
            $this->view('hrm/designations/edit', [
                'title' => 'Edit Designation',
                'designation' => array_merge($designation, $_POST),
                'branches' => $this->branches->all(),
                'departments' => $this->departments->allDepartments(true),
                'errors' => $errors,
            ]);
            return;
        }

        $this->designations->updateRecord($id, [
            'designation_name' => $name,
            'branch_id' => !empty($_POST['branch_id']) ? (int) $_POST['branch_id'] : null,
            'department_id' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
        ]);

        Audit::log('Update', 'HRM', 'Updated designation #' . $id . ' - ' . $name);
        Session::flash('success', 'Designation updated.');
        $this->redirect('/hrm/designations');
    }

    public function toggleActive(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/designations');
            return;
        }

        $designation = $this->designations->find($id);
        if (!$designation) {
            Session::flash('error', 'Designation not found.');
            $this->redirect('/hrm/designations');
            return;
        }

        $newState = (int) $designation['is_active'] === 1 ? 0 : 1;
        $this->designations->updateRecord($id, ['is_active' => $newState]);

        Audit::log('Update', 'HRM', ($newState ? 'Activated' : 'Deactivated') . ' designation #' . $id);
        Session::flash('success', 'Designation ' . ($newState ? 'activated' : 'deactivated') . '.');
        $this->redirect('/hrm/designations');
    }
}
