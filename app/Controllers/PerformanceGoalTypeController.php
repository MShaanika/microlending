<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\PerformanceGoalType;

class PerformanceGoalTypeController extends Controller
{
    private PerformanceGoalType $types;

    public function __construct()
    {
        $this->types = new PerformanceGoalType();
    }

    public function index(): void
    {
        Auth::authorize('performance.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->types->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Goal Types',
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
            $this->fragment('performance/goal-types/index', $data);
            return;
        }
        $this->view('performance/goal-types/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('performance.manage');
        $data = ['title' => 'Add Goal Type', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('performance/goal-types/create', $data);
            return;
        }
        $this->view('performance/goal-types/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/goal-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('performance/goal-types/create', [
                'title' => 'Add Goal Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Performance', 'Created goal type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Goal type created.');
        }
        Session::flash('success', 'Goal type created.');
        $this->redirect('/performance/goal-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('performance.manage');
        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Goal type not found.'], 404);
            }
            Session::flash('error', 'Goal type not found.');
            $this->redirect('/performance/goal-types');
            return;
        }
        $data = ['title' => 'Edit Goal Type', 'type' => $type, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('performance/goal-types/edit', $data);
            return;
        }
        $this->view('performance/goal-types/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/goal-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Goal type not found.'], 404);
            }
            Session::flash('error', 'Goal type not found.');
            $this->redirect('/performance/goal-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('performance/goal-types/edit', [
                'title' => 'Edit Goal Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'Performance', 'Updated goal type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Goal type updated.');
        }
        Session::flash('success', 'Goal type updated.');
        $this->redirect('/performance/goal-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/goal-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This goal type is used by existing employee goals and cannot be deleted.');
            $this->redirect('/performance/goal-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'Performance', 'Deleted goal type #' . $id);
        Session::flash('success', 'Goal type deleted.');
        $this->redirect('/performance/goal-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->types->nameExists($name, $excludeId)) {
            $errors['name'] = 'A goal type with this name already exists.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
