<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\HrmDepartment;
use App\Models\Trainer;

class TrainerController extends Controller
{
    private Trainer $trainers;
    private HrmDepartment $departments;
    private Branch $branches;

    public function __construct()
    {
        $this->trainers = new Trainer();
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

        $result = $this->trainers->paginated($search, $sort, $dir, $page, $perPage);

        $data = [
            'title' => 'Trainers',
            'trainers' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ];

        if ($this->isAjax()) {
            $this->fragment('training/trainers/index', $data);
            return;
        }
        $this->view('training/trainers/index', $data);
    }

    public function create(): void
    {
        Auth::authorize('training.manage');
        $data = [
            'title' => 'Add Trainer',
            'departments' => $this->departments->allDepartments(),
            'branches' => $this->branches->all(),
            'old' => [],
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('training/trainers/create', $data);
            return;
        }
        $this->view('training/trainers/create', $data);
    }

    public function store(): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainers/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('training/trainers/create', [
                'title' => 'Add Trainer',
                'departments' => $this->departments->allDepartments(),
                'branches' => $this->branches->all(),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->trainers->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Training', 'Created trainer #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Trainer created.');
        }
        Session::flash('success', 'Trainer created.');
        $this->redirect('/training/trainers');
    }

    public function edit(int $id): void
    {
        Auth::authorize('training.manage');
        $trainer = $this->trainers->find($id);
        if (!$trainer) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Trainer not found.'], 404);
            }
            Session::flash('error', 'Trainer not found.');
            $this->redirect('/training/trainers');
            return;
        }
        $data = [
            'title' => 'Edit Trainer',
            'trainer' => $trainer,
            'departments' => $this->departments->allDepartments(),
            'branches' => $this->branches->all(),
            'errors' => [],
        ];

        if ($this->isAjax()) {
            $this->fragment('training/trainers/edit', $data);
            return;
        }
        $this->view('training/trainers/edit', $data);
    }

    public function update(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainers/' . $id . '/edit');
            return;
        }

        $trainer = $this->trainers->find($id);
        if (!$trainer) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'Trainer not found.'], 404);
            }
            Session::flash('error', 'Trainer not found.');
            $this->redirect('/training/trainers');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            if ($this->isAjax()) {
                $this->jsonErrors($errors);
            }
            $this->view('training/trainers/edit', [
                'title' => 'Edit Trainer',
                'trainer' => array_merge($trainer, $_POST),
                'departments' => $this->departments->allDepartments(),
                'branches' => $this->branches->all(),
                'errors' => $errors,
            ]);
            return;
        }

        $this->trainers->updateRecord($id, $data);

        Audit::log('Update', 'Training', 'Updated trainer #' . $id . ' - ' . $data['name']);

        if ($this->isAjax()) {
            $this->jsonSuccess('Trainer updated.');
        }
        Session::flash('success', 'Trainer updated.');
        $this->redirect('/training/trainers');
    }

    public function delete(int $id): void
    {
        Auth::authorize('training.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            if ($this->isAjax()) {
                $this->jsonCsrfFailure();
            }
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/training/trainers');
            return;
        }

        if ($this->trainers->inUseCount($id) > 0) {
            if ($this->isAjax()) {
                $this->jsonErrors(['_general' => 'This trainer has trainings assigned to them and cannot be deleted.'], 422);
            }
            Session::flash('error', 'This trainer has trainings assigned to them and cannot be deleted.');
            $this->redirect('/training/trainers');
            return;
        }

        $this->trainers->delete($id);
        Audit::log('Delete', 'Training', 'Deleted trainer #' . $id);

        if ($this->isAjax()) {
            $this->jsonSuccess('Trainer deleted.');
        }
        Session::flash('success', 'Trainer deleted.');
        $this->redirect('/training/trainers');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $email = trim($post['email'] ?? '');
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->trainers->emailExists($email, $excludeId)) {
            $errors['email'] = 'A trainer with this email already exists.';
        }

        $data = [
            'name' => $name,
            'contact' => trim($post['contact'] ?? '') ?: null,
            'email' => $email ?: null,
            'experience' => trim($post['experience'] ?? '') ?: null,
            'expertise' => trim($post['expertise'] ?? '') ?: null,
            'qualification' => trim($post['qualification'] ?? '') ?: null,
            'branch_id' => !empty($post['branch_id']) ? (int) $post['branch_id'] : null,
            'department_id' => !empty($post['department_id']) ? (int) $post['department_id'] : null,
            'status' => in_array($post['status'] ?? '', ['Active', 'Inactive'], true) ? $post['status'] : 'Active',
        ];

        return [$data, $errors];
    }
}
