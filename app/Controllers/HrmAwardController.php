<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAward;
use App\Models\HrmAwardType;
use App\Models\HrmEmployee;

class HrmAwardController extends Controller
{
    private HrmAward $awards;
    private HrmEmployee $employees;
    private HrmAwardType $awardTypes;

    public function __construct()
    {
        $this->awards = new HrmAward();
        $this->employees = new HrmEmployee();
        $this->awardTypes = new HrmAwardType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'employee_id' => $_GET['employee_id'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'award_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->awards->paginated($filters, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Awards',
            'awards' => $result['rows'],
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
            $this->fragment('hrm/awards/index', $data);
            return;
        }
        $this->view('hrm/awards/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = [
            'title' => 'Add Award',
            'employees' => $this->employees->allEmployees(),
            'awardTypes' => $this->awardTypes->allTypes(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/awards/create', $data);
            return;
        }
        $this->view('hrm/awards/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/awards/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/awards/create', [
                'title' => 'Add Award',
                'employees' => $this->employees->allEmployees(),
                'awardTypes' => $this->awardTypes->allTypes(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->awards->create($data);

        Audit::log('Create', 'HRM', 'Recorded award #' . $id . ' for employee #' . $data['employee_id']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Award recorded.');
        }
        Session::flash('success', 'Award recorded.');
        $this->redirect('/hrm/awards');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $award = $this->awards->find($id);
        if (!$award) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Award not found.'], 404);
            }
            Session::flash('error', 'Award not found.');
            $this->redirect('/hrm/awards');
            return;
        }
        $data = ['title' => 'Award', 'award' => $award];

        if ($this->isAjax()) {
            $this->fragment('hrm/awards/show', $data);
            return;
        }
        $this->view('hrm/awards/show', $data);
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $award = $this->awards->find($id);
        if (!$award) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Award not found.'], 404);
            }
            Session::flash('error', 'Award not found.');
            $this->redirect('/hrm/awards');
            return;
        }
        $data = [
            'title' => 'Edit Award',
            'award' => $award,
            'employees' => $this->employees->allEmployees(),
            'awardTypes' => $this->awardTypes->allTypes(),
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('hrm/awards/edit', $data);
            return;
        }
        $this->view('hrm/awards/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/awards/' . $id . '/edit');
            return;
        }

        $award = $this->awards->find($id);
        if (!$award) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Award not found.'], 404);
            }
            Session::flash('error', 'Award not found.');
            $this->redirect('/hrm/awards');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/awards/edit', [
                'title' => 'Edit Award',
                'award' => array_merge($award, $_POST),
                'employees' => $this->employees->allEmployees(),
                'awardTypes' => $this->awardTypes->allTypes(),
                'errors' => $errors,
            ]);
            return;
        }

        $this->awards->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated award #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Award updated.');
        }
        Session::flash('success', 'Award updated.');
        $this->redirect('/hrm/awards/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/awards');
            return;
        }

        $this->awards->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted award #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Award deleted.');
        }
        Session::flash('success', 'Award deleted.');
        $this->redirect('/hrm/awards');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $employeeId = !empty($post['employee_id']) ? (int) $post['employee_id'] : null;
        $awardDate = trim($post['award_date'] ?? '');

        if (!$employeeId) {
            $errors['employee_id'] = 'Select an employee.';
        }
        if ($awardDate === '') {
            $errors['award_date'] = 'Award date is required.';
        }

        $data = [
            'employee_id' => $employeeId,
            'award_type_id' => !empty($post['award_type_id']) ? (int) $post['award_type_id'] : null,
            'award_date' => $awardDate ?: null,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
