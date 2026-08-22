<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAllowance;
use App\Models\HrmAllowanceType;
use App\Models\HrmEmployee;

class HrmAllowanceController extends Controller
{
    private HrmAllowance $allowances;
    private HrmAllowanceType $types;
    private HrmEmployee $employees;

    public function __construct()
    {
        $this->allowances = new HrmAllowance();
        $this->types = new HrmAllowanceType();
        $this->employees = new HrmEmployee();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'employee');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->allowances->paginated($filters, $sort, $dir, $page, $perPage);

        $this->view('hrm/allowances/index', [
            'title' => 'Employee Allowances',
            'allowances' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'employees' => $this->employees->allEmployees(),
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/allowances/create', [
            'title' => 'Assign Allowance',
            'employees' => $this->employees->allEmployees(['status' => 'Active']),
            'types' => $this->types->allTypes(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/allowances/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/allowances/create', [
                'title' => 'Assign Allowance',
                'employees' => $this->employees->allEmployees(['status' => 'Active']),
                'types' => $this->types->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->allowances->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Assigned allowance #' . $id);
        Session::flash('success', 'Allowance assigned.');
        $this->redirect('/hrm/allowances');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $allowance = $this->allowances->find($id);
        if (!$allowance) {
            Session::flash('error', 'Allowance not found.');
            $this->redirect('/hrm/allowances');
            return;
        }
        $this->view('hrm/allowances/edit', [
            'title' => 'Edit Allowance',
            'allowance' => $allowance,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/allowances/' . $id . '/edit');
            return;
        }

        $allowance = $this->allowances->find($id);
        if (!$allowance) {
            Session::flash('error', 'Allowance not found.');
            $this->redirect('/hrm/allowances');
            return;
        }

        $type = in_array($_POST['type'] ?? '', ['Fixed', 'Percentage'], true) ? $_POST['type'] : 'Fixed';
        $amount = (float) ($_POST['amount'] ?? 0);

        $this->allowances->updateRecord($id, ['type' => $type, 'amount' => $amount]);

        Audit::log('Update', 'HRM', 'Updated allowance #' . $id);
        Session::flash('success', 'Allowance updated.');
        $this->redirect('/hrm/allowances');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/allowances');
            return;
        }

        $this->allowances->delete($id);
        Audit::log('Delete', 'HRM', 'Removed allowance #' . $id);
        Session::flash('success', 'Allowance removed.');
        $this->redirect('/hrm/allowances');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $allowanceTypeId = !empty($post['allowance_type_id']) ? (int) $post['allowance_type_id'] : null;
        $type = in_array($post['type'] ?? '', ['Fixed', 'Percentage'], true) ? $post['type'] : 'Fixed';
        $amount = (float) ($post['amount'] ?? 0);

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if (!$allowanceTypeId) {
            $errors['allowance_type_id'] = 'Select an allowance type.';
        }
        if ($employeeId && $allowanceTypeId && $this->allowances->assignmentExists($employeeId, $allowanceTypeId)) {
            $errors['allowance_type_id'] = 'This employee already has this allowance type assigned -- edit the existing one instead.';
        }

        $data = [
            'employee_id' => $employeeId,
            'allowance_type_id' => $allowanceTypeId,
            'type' => $type,
            'amount' => $amount,
        ];

        return [$data, $errors];
    }
}
