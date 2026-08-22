<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmDeduction;
use App\Models\HrmDeductionType;
use App\Models\HrmEmployee;

class HrmDeductionController extends Controller
{
    private HrmDeduction $deductions;
    private HrmDeductionType $types;
    private HrmEmployee $employees;

    public function __construct()
    {
        $this->deductions = new HrmDeduction();
        $this->types = new HrmDeductionType();
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

        $result = $this->deductions->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Employee Deductions',
            'deductions' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'employees' => $this->employees->allEmployees(),
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/deductions/index', $data);
            return;
        }
        $this->view('hrm/deductions/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'Assign Deduction',
            'employees' => $this->employees->allEmployees(['status' => 'Active']),
            'types' => $this->types->allTypes(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/deductions/create', $data);
            return;
        }
        $this->view('hrm/deductions/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/deductions/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/deductions/create', [
                'title' => 'Assign Deduction',
                'employees' => $this->employees->allEmployees(['status' => 'Active']),
                'types' => $this->types->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->deductions->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Assigned deduction #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Deduction assigned.');
        }
        Session::flash('success', 'Deduction assigned.');
        $this->redirect('/hrm/deductions');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $deduction = $this->deductions->find($id);
        if (!$deduction) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Deduction not found.'], 404);
            }
            Session::flash('error', 'Deduction not found.');
            $this->redirect('/hrm/deductions');
            return;
        }
        $data = ['title' => 'Edit Deduction', 'deduction' => $deduction, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/deductions/edit', $data);
            return;
        }
        $this->view('hrm/deductions/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/deductions/' . $id . '/edit');
            return;
        }

        $deduction = $this->deductions->find($id);
        if (!$deduction) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Deduction not found.'], 404);
            }
            Session::flash('error', 'Deduction not found.');
            $this->redirect('/hrm/deductions');
            return;
        }

        $type = in_array($_POST['type'] ?? '', ['Fixed', 'Percentage'], true) ? $_POST['type'] : 'Fixed';
        $amount = (float) ($_POST['amount'] ?? 0);

        $this->deductions->updateRecord($id, ['type' => $type, 'amount' => $amount]);

        Audit::log('Update', 'HRM', 'Updated deduction #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Deduction updated.');
        }
        Session::flash('success', 'Deduction updated.');
        $this->redirect('/hrm/deductions');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/deductions');
            return;
        }

        $this->deductions->delete($id);
        Audit::log('Delete', 'HRM', 'Removed deduction #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Deduction removed.');
        }
        Session::flash('success', 'Deduction removed.');
        $this->redirect('/hrm/deductions');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $deductionTypeId = !empty($post['deduction_type_id']) ? (int) $post['deduction_type_id'] : null;
        $type = in_array($post['type'] ?? '', ['Fixed', 'Percentage'], true) ? $post['type'] : 'Fixed';
        $amount = (float) ($post['amount'] ?? 0);

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if (!$deductionTypeId) {
            $errors['deduction_type_id'] = 'Select a deduction type.';
        }
        if ($employeeId && $deductionTypeId && $this->deductions->assignmentExists($employeeId, $deductionTypeId)) {
            $errors['deduction_type_id'] = 'This employee already has this deduction type assigned -- edit the existing one instead.';
        }

        $data = [
            'employee_id' => $employeeId,
            'deduction_type_id' => $deductionTypeId,
            'type' => $type,
            'amount' => $amount,
        ];

        return [$data, $errors];
    }
}
