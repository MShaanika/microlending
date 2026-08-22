<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\HrmTermination;
use App\Models\HrmTerminationType;

class HrmTerminationController extends Controller
{
    private HrmTermination $terminations;
    private HrmEmployee $employees;
    private HrmTerminationType $terminationTypes;

    public function __construct()
    {
        $this->terminations = new HrmTermination();
        $this->employees = new HrmEmployee();
        $this->terminationTypes = new HrmTerminationType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'status' => $_GET['status'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'created_at');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->terminations->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Terminations',
            'terminations' => $result['rows'],
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
            $this->fragment('hrm/terminations/index', $data);
            return;
        }
        $this->view('hrm/terminations/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'Record a Termination',
            'employees' => $this->employees->allEmployees(),
            'terminationTypes' => $this->terminationTypes->allTypes(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/terminations/create', $data);
            return;
        }
        $this->view('hrm/terminations/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/terminations/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/terminations/create', [
                'title' => 'Record a Termination',
                'employees' => $this->employees->allEmployees(),
                'terminationTypes' => $this->terminationTypes->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->terminations->create($data);

        Audit::log('Create', 'HRM', 'Termination #' . $id . ' recorded for employee #' . $data['employee_id']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Termination recorded.');
        }
        Session::flash('success', 'Termination recorded.');
        $this->redirect('/hrm/terminations');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $termination = $this->terminations->find($id);
        if (!$termination) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Termination not found.'], 404);
            }
            Session::flash('error', 'Termination not found.');
            $this->redirect('/hrm/terminations');
            return;
        }
        $data = ['title' => 'Termination', 'termination' => $termination];

        if ($this->isAjax()) {
            $this->fragment('hrm/terminations/show', $data);
            return;
        }
        $this->view('hrm/terminations/show', $data);
    }

    public function approve(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/terminations/' . $id);
            return;
        }

        $termination = $this->terminations->find($id);
        if (!$termination || $termination['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending terminations can be approved.'], 422);
            }
            Session::flash('error', 'Only pending terminations can be approved.');
            $this->redirect('/hrm/terminations');
            return;
        }

        $this->terminations->updateRecord($id, [
            'status' => 'Approved',
            'approved_by' => Auth::user()['id'] ?? null,
        ]);
        $this->employees->updateRecord((int) $termination['employee_id'], ['status' => 'Terminated']);

        Audit::log('Update', 'HRM', 'Termination #' . $id . ' approved; employee #' . $termination['employee_id'] . ' marked Terminated');

        if ($this->isAjax()) {
            $this->jsonSuccess('Termination approved. Employee marked as Terminated.', '/hrm/terminations/' . $id);
        }
        Session::flash('success', 'Termination approved. Employee marked as Terminated.');
        $this->redirect('/hrm/terminations/' . $id);
    }

    public function reject(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/terminations/' . $id);
            return;
        }

        $termination = $this->terminations->find($id);
        if (!$termination || $termination['status'] !== 'Pending') {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Only pending terminations can be rejected.'], 422);
            }
            Session::flash('error', 'Only pending terminations can be rejected.');
            $this->redirect('/hrm/terminations');
            return;
        }

        $this->terminations->updateRecord($id, [
            'status' => 'Rejected',
            'approved_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'HRM', 'Termination #' . $id . ' rejected');

        if ($this->isAjax()) {
            $this->jsonSuccess('Termination rejected.', '/hrm/terminations/' . $id);
        }
        Session::flash('success', 'Termination rejected.');
        $this->redirect('/hrm/terminations/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/terminations');
            return;
        }

        $this->terminations->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted termination #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Termination deleted.');
        }
        Session::flash('success', 'Termination deleted.');
        $this->redirect('/hrm/terminations');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $reason = trim($post['reason'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if ($reason === '') {
            $errors['reason'] = 'Reason is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'termination_type_id' => !empty($post['termination_type_id']) ? (int) $post['termination_type_id'] : null,
            'notice_date' => trim($post['notice_date'] ?? '') ?: null,
            'termination_date' => trim($post['termination_date'] ?? '') ?: null,
            'reason' => $reason,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
