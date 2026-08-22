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
use App\Models\HrmEmployee;
use App\Models\HrmTransfer;

class HrmTransferController extends Controller
{
    private HrmTransfer $transfers;
    private HrmEmployee $employees;
    private Branch $branches;
    private HrmDepartment $departments;
    private HrmDesignation $designations;

    public function __construct()
    {
        $this->transfers = new HrmTransfer();
        $this->employees = new HrmEmployee();
        $this->branches = new Branch();
        $this->departments = new HrmDepartment();
        $this->designations = new HrmDesignation();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'effective_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->transfers->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Transfers',
            'transfers' => $result['rows'],
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
            $this->fragment('hrm/transfers/index', $data);
            return;
        }
        $this->view('hrm/transfers/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'Record a Transfer',
            'employees' => $this->employees->allEmployees(),
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'designations' => $this->designations->allDesignations(true),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/transfers/create', $data);
            return;
        }
        $this->view('hrm/transfers/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/transfers/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/transfers/create', [
                'title' => 'Record a Transfer',
                'employees' => $this->employees->allEmployees(),
                'branches' => $this->branches->all(),
                'departments' => $this->departments->allDepartments(true),
                'designations' => $this->designations->allDesignations(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $employee = $this->employees->find($data['employee_id']);

        $data['from_branch_id'] = $employee['branch_id'];
        $data['from_department_id'] = $employee['department_id'];
        $data['from_designation_id'] = $employee['designation_id'];
        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->transfers->create($data);

        Audit::log('Create', 'HRM', 'Transfer #' . $id . ' recorded for employee #' . $data['employee_id']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Transfer recorded. Employee will move once approved.');
        }
        Session::flash('success', 'Transfer recorded. Employee will move once approved.');
        $this->redirect('/hrm/transfers');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $transfer = $this->transfers->find($id);
        if (!$transfer) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Transfer not found.'], 404);
            }
            Session::flash('error', 'Transfer not found.');
            $this->redirect('/hrm/transfers');
            return;
        }
        $data = ['title' => 'Transfer', 'transfer' => $transfer];

        if ($this->isAjax()) {
            $this->fragment('hrm/transfers/show', $data);
            return;
        }
        $this->view('hrm/transfers/show', $data);
    }

    public function approve(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/transfers/' . $id);
            return;
        }

        $transfer = $this->transfers->find($id);
        if (!$transfer || $transfer['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending transfers can be approved.'], 422);
            }
            Session::flash('error', 'Only pending transfers can be approved.');
            $this->redirect('/hrm/transfers');
            return;
        }

        $this->transfers->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => Auth::user()['id'] ?? null,
            'transfer_date' => date('Y-m-d'),
        ]);
        $this->employees->updateRecord((int) $transfer['employee_id'], [
            'branch_id' => $transfer['to_branch_id'],
            'department_id' => $transfer['to_department_id'],
            'designation_id' => $transfer['to_designation_id'],
        ]);

        Audit::log('Update', 'HRM', 'Transfer #' . $id . ' approved; employee #' . $transfer['employee_id'] . ' moved');

        if ($this->isAjax()) {
            $this->jsonSuccess('Transfer approved. Employee record updated.', '/hrm/transfers/' . $id);
        }
        Session::flash('success', 'Transfer approved. Employee record updated.');
        $this->redirect('/hrm/transfers/' . $id);
    }

    public function reject(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/transfers/' . $id);
            return;
        }

        $transfer = $this->transfers->find($id);
        if (!$transfer || $transfer['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending transfers can be rejected.'], 422);
            }
            Session::flash('error', 'Only pending transfers can be rejected.');
            $this->redirect('/hrm/transfers');
            return;
        }

        $this->transfers->updateRecord($id, [
            'status' => 'Rejected',
            'approved_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'HRM', 'Transfer #' . $id . ' rejected');

        if ($this->isAjax()) {
            $this->jsonSuccess('Transfer rejected.', '/hrm/transfers/' . $id);
        }
        Session::flash('success', 'Transfer rejected.');
        $this->redirect('/hrm/transfers/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/transfers');
            return;
        }

        $this->transfers->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted transfer #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Transfer deleted.');
        }
        Session::flash('success', 'Transfer deleted.');
        $this->redirect('/hrm/transfers');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $effectiveDate = trim($post['effective_date'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        } elseif (!$this->employees->find($employeeId)) {
            $errors['employee_id'] = 'Employee not found.';
        }
        if ($effectiveDate === '') {
            $errors['effective_date'] = 'Effective date is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'to_branch_id' => !empty($post['to_branch_id']) ? (int) $post['to_branch_id'] : null,
            'to_department_id' => !empty($post['to_department_id']) ? (int) $post['to_department_id'] : null,
            'to_designation_id' => !empty($post['to_designation_id']) ? (int) $post['to_designation_id'] : null,
            'effective_date' => $effectiveDate ?: null,
            'reason' => trim($post['reason'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
