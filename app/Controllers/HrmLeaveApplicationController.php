<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\HrmLeaveApplication;
use App\Models\HrmLeaveType;
use DateTime;

class HrmLeaveApplicationController extends Controller
{
    private HrmLeaveApplication $applications;
    private HrmEmployee $employees;
    private HrmLeaveType $leaveTypes;

    public function __construct()
    {
        $this->applications = new HrmLeaveApplication();
        $this->employees = new HrmEmployee();
        $this->leaveTypes = new HrmLeaveType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'start_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->applications->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Leave Applications',
            'applications' => $result['rows'],
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
            $this->fragment('hrm/leave-applications/index', $data);
            return;
        }
        $this->view('hrm/leave-applications/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'Apply for Leave',
            'employees' => $this->employees->allEmployees(),
            'leaveTypes' => $this->leaveTypes->allLeaveTypes(true),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/leave-applications/create', $data);
            return;
        }
        $this->view('hrm/leave-applications/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/leave-applications/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/leave-applications/create', [
                'title' => 'Apply for Leave',
                'employees' => $this->employees->allEmployees(),
                'leaveTypes' => $this->leaveTypes->allLeaveTypes(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->applications->create($data);

        Audit::log('Create', 'HRM', 'Leave application #' . $id . ' submitted for employee #' . $data['employee_id']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Leave application submitted.');
        }
        Session::flash('success', 'Leave application submitted.');
        $this->redirect('/hrm/leave-applications');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $application = $this->applications->find($id);
        if (!$application) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Leave application not found.'], 404);
            }
            Session::flash('error', 'Leave application not found.');
            $this->redirect('/hrm/leave-applications');
            return;
        }
        $data = ['title' => 'Leave Application', 'application' => $application];

        if ($this->isAjax()) {
            $this->fragment('hrm/leave-applications/show', $data);
            return;
        }
        $this->view('hrm/leave-applications/show', $data);
    }

    public function approve(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/leave-applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application || $application['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending applications can be approved.'], 422);
            }
            Session::flash('error', 'Only pending applications can be approved.');
            $this->redirect('/hrm/leave-applications');
            return;
        }

        $this->applications->updateRecord($id, [
            'status' => 'Approved',
            'approver_comment' => trim($_POST['approver_comment'] ?? '') ?: null,
            'approved_by' => Auth::user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Update', 'HRM', 'Approved leave application #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Leave application approved.', '/hrm/leave-applications/' . $id);
        }
        Session::flash('success', 'Leave application approved.');
        $this->redirect('/hrm/leave-applications/' . $id);
    }

    public function reject(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/leave-applications/' . $id);
            return;
        }

        $application = $this->applications->find($id);
        if (!$application || $application['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending applications can be rejected.'], 422);
            }
            Session::flash('error', 'Only pending applications can be rejected.');
            $this->redirect('/hrm/leave-applications');
            return;
        }

        $this->applications->updateRecord($id, [
            'status' => 'Rejected',
            'approver_comment' => trim($_POST['approver_comment'] ?? '') ?: null,
            'approved_by' => Auth::user()['id'] ?? null,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Update', 'HRM', 'Rejected leave application #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Leave application rejected.', '/hrm/leave-applications/' . $id);
        }
        Session::flash('success', 'Leave application rejected.');
        $this->redirect('/hrm/leave-applications/' . $id);
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $leaveTypeId = !empty($post['leave_type_id']) ? (int) $post['leave_type_id'] : null;
        $startDate = trim($post['start_date'] ?? '');
        $endDate = trim($post['end_date'] ?? '');
        $reason = trim($post['reason'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if (!$leaveTypeId) {
            $errors['leave_type_id'] = 'Select a leave type.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($endDate === '') {
            $errors['end_date'] = 'End date is required.';
        }
        if ($reason === '') {
            $errors['reason'] = 'A reason is required.';
        }

        $totalDays = 0;
        if ($startDate !== '' && $endDate !== '') {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            if ($end < $start) {
                $errors['end_date'] = 'End date cannot be before the start date.';
            } else {
                $totalDays = (int) $start->diff($end)->days + 1;
                if ($employeeId && $this->applications->overlapsExisting($employeeId, $startDate, $endDate)) {
                    $errors['start_date'] = 'This employee already has a pending or approved leave application overlapping these dates.';
                }
            }
        }

        $data = [
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'total_days' => $totalDays,
            'reason' => $reason,
        ];

        return [$data, $errors];
    }
}
