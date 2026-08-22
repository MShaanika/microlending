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
use App\Models\HrmPromotion;

class HrmPromotionController extends Controller
{
    private HrmPromotion $promotions;
    private HrmEmployee $employees;
    private Branch $branches;
    private HrmDepartment $departments;
    private HrmDesignation $designations;

    public function __construct()
    {
        $this->promotions = new HrmPromotion();
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

        $result = $this->promotions->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Promotions',
            'promotions' => $result['rows'],
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
            $this->fragment('hrm/promotions/index', $data);
            return;
        }
        $this->view('hrm/promotions/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'Record a Promotion',
            'employees' => $this->employees->allEmployees(),
            'branches' => $this->branches->all(),
            'departments' => $this->departments->allDepartments(true),
            'designations' => $this->designations->allDesignations(true),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/promotions/create', $data);
            return;
        }
        $this->view('hrm/promotions/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/promotions/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/promotions/create', [
                'title' => 'Record a Promotion',
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

        $data['previous_branch_id'] = $employee['branch_id'];
        $data['previous_department_id'] = $employee['department_id'];
        $data['previous_designation_id'] = $employee['designation_id'];
        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->promotions->create($data);

        $this->employees->updateRecord((int) $data['employee_id'], [
            'branch_id' => $data['current_branch_id'],
            'department_id' => $data['current_department_id'],
            'designation_id' => $data['current_designation_id'],
        ]);

        Audit::log('Create', 'HRM', 'Promotion #' . $id . ' recorded for employee #' . $data['employee_id'] . '; branch/department/designation updated');

        if ($this->isAjax()) {
            $this->jsonSuccess('Promotion recorded and employee record updated.');
        }
        Session::flash('success', 'Promotion recorded and employee record updated.');
        $this->redirect('/hrm/promotions');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $promotion = $this->promotions->find($id);
        if (!$promotion) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Promotion not found.'], 404);
            }
            Session::flash('error', 'Promotion not found.');
            $this->redirect('/hrm/promotions');
            return;
        }
        $data = ['title' => 'Promotion', 'promotion' => $promotion];

        if ($this->isAjax()) {
            $this->fragment('hrm/promotions/show', $data);
            return;
        }
        $this->view('hrm/promotions/show', $data);
    }

    public function approve(int $id): void
    {
        $this->decide($id, 'Approved');
    }

    public function reject(int $id): void
    {
        $this->decide($id, 'Rejected');
    }

    private function decide(int $id, string $status): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/promotions/' . $id);
            return;
        }

        $promotion = $this->promotions->find($id);
        if (!$promotion || $promotion['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending promotions can be decided.'], 422);
            }
            Session::flash('error', 'Only pending promotions can be decided.');
            $this->redirect('/hrm/promotions');
            return;
        }

        $this->promotions->updateRecord($id, [
            'status' => $status,
            'approved_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'HRM', 'Promotion #' . $id . ' ' . strtolower($status));

        if ($this->isAjax()) {
            $this->jsonSuccess('Promotion ' . strtolower($status) . '.', '/hrm/promotions/' . $id);
        }
        Session::flash('success', 'Promotion ' . strtolower($status) . '.');
        $this->redirect('/hrm/promotions/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/promotions');
            return;
        }

        $this->promotions->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted promotion #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Promotion deleted.');
        }
        Session::flash('success', 'Promotion deleted.');
        $this->redirect('/hrm/promotions');
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
            'current_branch_id' => !empty($post['current_branch_id']) ? (int) $post['current_branch_id'] : null,
            'current_department_id' => !empty($post['current_department_id']) ? (int) $post['current_department_id'] : null,
            'current_designation_id' => !empty($post['current_designation_id']) ? (int) $post['current_designation_id'] : null,
            'effective_date' => $effectiveDate ?: null,
            'reason' => trim($post['reason'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
