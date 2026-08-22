<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\HrmDepartment;

class HrmDepartmentController extends Controller
{
    private HrmDepartment $departments;
    private Branch $branches;

    public function __construct()
    {
        $this->departments = new HrmDepartment();
        $this->branches = new Branch();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->departments->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('hrm/departments/index', [
            'title' => 'Departments',
            'departments' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/departments/create', [
            'title' => 'Add Department',
            'branches' => $this->branches->all(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/departments/create');
            return;
        }

        $name = trim($_POST['department_name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['department_name'] = 'Department name is required.';
        } elseif ($this->departments->nameExists($name)) {
            $errors['department_name'] = 'A department with this name already exists.';
        }

        if (!empty($errors)) {
            $this->view('hrm/departments/create', [
                'title' => 'Add Department',
                'branches' => $this->branches->all(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->departments->create([
            'department_name' => $name,
            'branch_id' => !empty($_POST['branch_id']) ? (int) $_POST['branch_id'] : null,
            'is_active' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'HRM', 'Created department #' . $id . ' - ' . $name);
        Session::flash('success', 'Department created.');
        $this->redirect('/hrm/departments');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $department = $this->departments->find($id);
        if (!$department) {
            Session::flash('error', 'Department not found.');
            $this->redirect('/hrm/departments');
            return;
        }
        $this->view('hrm/departments/edit', [
            'title' => 'Edit Department',
            'department' => $department,
            'branches' => $this->branches->all(),
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/departments/' . $id . '/edit');
            return;
        }

        $department = $this->departments->find($id);
        if (!$department) {
            Session::flash('error', 'Department not found.');
            $this->redirect('/hrm/departments');
            return;
        }

        $name = trim($_POST['department_name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['department_name'] = 'Department name is required.';
        } elseif ($this->departments->nameExists($name, $id)) {
            $errors['department_name'] = 'A department with this name already exists.';
        }

        if (!empty($errors)) {
            $this->view('hrm/departments/edit', [
                'title' => 'Edit Department',
                'department' => array_merge($department, $_POST),
                'branches' => $this->branches->all(),
                'errors' => $errors,
            ]);
            return;
        }

        $this->departments->updateRecord($id, [
            'department_name' => $name,
            'branch_id' => !empty($_POST['branch_id']) ? (int) $_POST['branch_id'] : null,
        ]);

        Audit::log('Update', 'HRM', 'Updated department #' . $id . ' - ' . $name);
        Session::flash('success', 'Department updated.');
        $this->redirect('/hrm/departments');
    }

    public function toggleActive(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/departments');
            return;
        }

        $department = $this->departments->find($id);
        if (!$department) {
            Session::flash('error', 'Department not found.');
            $this->redirect('/hrm/departments');
            return;
        }

        $newState = (int) $department['is_active'] === 1 ? 0 : 1;
        $this->departments->updateRecord($id, ['is_active' => $newState]);

        Audit::log('Update', 'HRM', ($newState ? 'Activated' : 'Deactivated') . ' department #' . $id);
        Session::flash('success', 'Department ' . ($newState ? 'activated' : 'deactivated') . '.');
        $this->redirect('/hrm/departments');
    }
}
