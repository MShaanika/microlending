<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\HrmDepartment;
use App\Models\TrainingType;

class TrainingTypeController extends Controller
{
    private TrainingType $types;
    private HrmDepartment $departments;
    private Branch $branches;

    public function __construct()
    {
        $this->types = new TrainingType();
        $this->departments = new HrmDepartment();
        $this->branches = new Branch();
    }

    public function index(): void
    {
        Auth::authorize('training.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->types->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Training Types',
            'types' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('training/types/index', $data);
            return;
        }
        $this->view('training/types/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('training.manage');
        $data = [
            'title' => 'Add Training Type',
            'departments' => $this->departments->allDepartments(),
            'branches' => $this->branches->all(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('training/types/create', $data);
            return;
        }
        $this->view('training/types/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('training/types/create', [
                'title' => 'Add Training Type',
                'departments' => $this->departments->allDepartments(),
                'branches' => $this->branches->all(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Training', 'Created training type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Training type created.');
        }
        Session::flash('success', 'Training type created.');
        $this->redirect('/training/types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('training.manage');
        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Training type not found.'], 404);
            }
            Session::flash('error', 'Training type not found.');
            $this->redirect('/training/types');
            return;
        }
        $data = [
            'title' => 'Edit Training Type',
            'type' => $type,
            'departments' => $this->departments->allDepartments(),
            'branches' => $this->branches->all(),
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('training/types/edit', $data);
            return;
        }
        $this->view('training/types/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Training type not found.'], 404);
            }
            Session::flash('error', 'Training type not found.');
            $this->redirect('/training/types');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('training/types/edit', [
                'title' => 'Edit Training Type',
                'type' => array_merge($type, $_POST),
                'departments' => $this->departments->allDepartments(),
                'branches' => $this->branches->all(),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'Training', 'Updated training type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Training type updated.');
        }
        Session::flash('success', 'Training type updated.');
        $this->redirect('/training/types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This training type has trainings assigned to it and cannot be deleted.');
            $this->redirect('/training/types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'Training', 'Deleted training type #' . $id);
        Session::flash('success', 'Training type deleted.');
        $this->redirect('/training/types');
    }

    private function validate(array $post): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
            'branch_id' => !empty($post['branch_id']) ? (int) $post['branch_id'] : null,
            'department_id' => !empty($post['department_id']) ? (int) $post['department_id'] : null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
