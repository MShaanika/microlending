<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\HrmWarning;
use App\Models\HrmWarningType;

class HrmWarningController extends Controller
{
    private HrmWarning $warnings;
    private HrmEmployee $employees;
    private HrmWarningType $warningTypes;

    public function __construct()
    {
        $this->warnings = new HrmWarning();
        $this->employees = new HrmEmployee();
        $this->warningTypes = new HrmWarningType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'warning_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->warnings->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Warnings',
            'warnings' => $result['rows'],
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
            $this->fragment('hrm/warnings/index', $data);
            return;
        }
        $this->view('hrm/warnings/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'Issue a Warning',
            'employees' => $this->employees->allEmployees(),
            'warningTypes' => $this->warningTypes->allTypes(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/warnings/create', $data);
            return;
        }
        $this->view('hrm/warnings/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/warnings/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/warnings/create', [
                'title' => 'Issue a Warning',
                'employees' => $this->employees->allEmployees(),
                'warningTypes' => $this->warningTypes->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['status'] = 'Pending';
        $data['warning_by'] = Auth::user()['id'] ?? null;
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->warnings->create($data);

        Audit::log('Create', 'HRM', 'Warning #' . $id . ' issued to employee #' . $data['employee_id']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Warning issued.');
        }
        Session::flash('success', 'Warning issued.');
        $this->redirect('/hrm/warnings');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $warning = $this->warnings->find($id);
        if (!$warning) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Warning not found.'], 404);
            }
            Session::flash('error', 'Warning not found.');
            $this->redirect('/hrm/warnings');
            return;
        }
        $data = ['title' => 'Warning', 'warning' => $warning];

        if ($this->isAjax()) {
            $this->fragment('hrm/warnings/show', $data);
            return;
        }
        $this->view('hrm/warnings/show', $data);
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
            $this->redirect('/hrm/warnings/' . $id);
            return;
        }

        $warning = $this->warnings->find($id);
        if (!$warning || $warning['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending warnings can be decided.'], 422);
            }
            Session::flash('error', 'Only pending warnings can be decided.');
            $this->redirect('/hrm/warnings');
            return;
        }

        $this->warnings->updateRecord($id, ['status' => $status]);

        Audit::log('Update', 'HRM', 'Warning #' . $id . ' ' . strtolower($status));

        if ($this->isAjax()) {
            $this->jsonSuccess('Warning ' . strtolower($status) . '.', '/hrm/warnings/' . $id);
        }
        Session::flash('success', 'Warning ' . strtolower($status) . '.');
        $this->redirect('/hrm/warnings/' . $id);
    }

    public function respond(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/warnings/' . $id);
            return;
        }

        $warning = $this->warnings->find($id);
        if (!$warning) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Warning not found.'], 404);
            }
            Session::flash('error', 'Warning not found.');
            $this->redirect('/hrm/warnings');
            return;
        }

        $response = trim($_POST['employee_response'] ?? '');
        if ($response === '') {
            if ($this->isAjax()) {
                $this->jsonErrors(['employee_response' => 'A response is required.']);
            }
            Session::flash('error', 'A response is required.');
            $this->redirect('/hrm/warnings/' . $id);
            return;
        }

        $this->warnings->updateRecord($id, [
            'employee_response' => $response,
            'responded_at' => date('Y-m-d H:i:s'),
        ]);

        Audit::log('Update', 'HRM', 'Employee response recorded for warning #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Response recorded.', '/hrm/warnings/' . $id);
        }
        Session::flash('success', 'Response recorded.');
        $this->redirect('/hrm/warnings/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/warnings');
            return;
        }

        $this->warnings->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted warning #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Warning deleted.');
        }
        Session::flash('success', 'Warning deleted.');
        $this->redirect('/hrm/warnings');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $subject = trim($post['subject'] ?? '');
        $warningDate = trim($post['warning_date'] ?? '');
        $severity = in_array($post['severity'] ?? '', ['Low', 'Medium', 'High'], true) ? $post['severity'] : 'Low';

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if ($subject === '') {
            $errors['subject'] = 'Subject is required.';
        }
        if ($warningDate === '') {
            $errors['warning_date'] = 'Warning date is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'warning_type_id' => !empty($post['warning_type_id']) ? (int) $post['warning_type_id'] : null,
            'subject' => $subject,
            'severity' => $severity,
            'warning_date' => $warningDate ?: null,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
