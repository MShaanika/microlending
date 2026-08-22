<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmDeductionType;

class HrmDeductionTypeController extends Controller
{
    private HrmDeductionType $types;

    public function __construct()
    {
        $this->types = new HrmDeductionType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->types->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Deduction Types',
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
            $this->fragment('hrm/deduction-types/index', $data);
            return;
        }
        $this->view('hrm/deduction-types/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = ['title' => 'Add Deduction Type', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/deduction-types/create', $data);
            return;
        }
        $this->view('hrm/deduction-types/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/deduction-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/deduction-types/create', [
                'title' => 'Add Deduction Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Created deduction type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Deduction type created.');
        }
        Session::flash('success', 'Deduction type created.');
        $this->redirect('/hrm/deduction-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Deduction type not found.'], 404);
            }
            Session::flash('error', 'Deduction type not found.');
            $this->redirect('/hrm/deduction-types');
            return;
        }
        $data = ['title' => 'Edit Deduction Type', 'type' => $type, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/deduction-types/edit', $data);
            return;
        }
        $this->view('hrm/deduction-types/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/deduction-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Deduction type not found.'], 404);
            }
            Session::flash('error', 'Deduction type not found.');
            $this->redirect('/hrm/deduction-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/deduction-types/edit', [
                'title' => 'Edit Deduction Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated deduction type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Deduction type updated.');
        }
        Session::flash('success', 'Deduction type updated.');
        $this->redirect('/hrm/deduction-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/deduction-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This deduction type is assigned to employees and cannot be deleted.');
            $this->redirect('/hrm/deduction-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted deduction type #' . $id);
        Session::flash('success', 'Deduction type deleted.');
        $this->redirect('/hrm/deduction-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->types->nameExists($name, $excludeId)) {
            $errors['name'] = 'A deduction type with this name already exists.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
