<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmAwardType;

class HrmAwardTypeController extends Controller
{
    private HrmAwardType $types;

    public function __construct()
    {
        $this->types = new HrmAwardType();
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
            'title' => 'Award Types',
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
            $this->fragment('hrm/award-types/index', $data);
            return;
        }
        $this->view('hrm/award-types/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $data = ['title' => 'Add Award Type', 'old' => [], 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/award-types/create', $data);
            return;
        }
        $this->view('hrm/award-types/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/award-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/award-types/create', [
                'title' => 'Add Award Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Created award type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Award type created.');
        }
        Session::flash('success', 'Award type created.');
        $this->redirect('/hrm/award-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Award type not found.'], 404);
            }
            Session::flash('error', 'Award type not found.');
            $this->redirect('/hrm/award-types');
            return;
        }
        $data = ['title' => 'Edit Award Type', 'type' => $type, 'errors' => []];

        if ($this->isAjax()) {
            $this->fragment('hrm/award-types/edit', $data);
            return;
        }
        $this->view('hrm/award-types/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/award-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Award type not found.'], 404);
            }
            Session::flash('error', 'Award type not found.');
            $this->redirect('/hrm/award-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('hrm/award-types/edit', [
                'title' => 'Edit Award Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated award type #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Award type updated.');
        }
        Session::flash('success', 'Award type updated.');
        $this->redirect('/hrm/award-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/award-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This award type is used by existing awards and cannot be deleted.');
            $this->redirect('/hrm/award-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted award type #' . $id);
        Session::flash('success', 'Award type deleted.');
        $this->redirect('/hrm/award-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->types->nameExists($name, $excludeId)) {
            $errors['name'] = 'An award type with this name already exists.';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $errors];
    }
}
