<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmLeaveType;

class HrmLeaveTypeController extends Controller
{
    private HrmLeaveType $leaveTypes;

    public function __construct()
    {
        $this->leaveTypes = new HrmLeaveType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->leaveTypes->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('hrm/leave-types/index', [
            'title' => 'Leave Types',
            'leaveTypes' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/leave-types/create', [
            'title' => 'Add Leave Type',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/leave-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/leave-types/create', [
                'title' => 'Add Leave Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->leaveTypes->create(array_merge($data, [
            'is_active' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]));

        Audit::log('Create', 'HRM', 'Created leave type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Leave type created.');
        $this->redirect('/hrm/leave-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $leaveType = $this->leaveTypes->find($id);
        if (!$leaveType) {
            Session::flash('error', 'Leave type not found.');
            $this->redirect('/hrm/leave-types');
            return;
        }
        $this->view('hrm/leave-types/edit', [
            'title' => 'Edit Leave Type',
            'leaveType' => $leaveType,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/leave-types/' . $id . '/edit');
            return;
        }

        $leaveType = $this->leaveTypes->find($id);
        if (!$leaveType) {
            Session::flash('error', 'Leave type not found.');
            $this->redirect('/hrm/leave-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/leave-types/edit', [
                'title' => 'Edit Leave Type',
                'leaveType' => array_merge($leaveType, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->leaveTypes->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated leave type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Leave type updated.');
        $this->redirect('/hrm/leave-types');
    }

    public function toggleActive(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/leave-types');
            return;
        }

        $leaveType = $this->leaveTypes->find($id);
        if (!$leaveType) {
            Session::flash('error', 'Leave type not found.');
            $this->redirect('/hrm/leave-types');
            return;
        }

        $newState = (int) $leaveType['is_active'] === 1 ? 0 : 1;
        $this->leaveTypes->updateRecord($id, ['is_active' => $newState]);

        Audit::log('Update', 'HRM', ($newState ? 'Activated' : 'Deactivated') . ' leave type #' . $id);
        Session::flash('success', 'Leave type ' . ($newState ? 'activated' : 'deactivated') . '.');
        $this->redirect('/hrm/leave-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Leave type name is required.';
        } elseif ($this->leaveTypes->nameExists($name, $excludeId)) {
            $errors['name'] = 'A leave type with this name already exists.';
        }

        $color = trim($post['color'] ?? '') ?: '#FF6B6B';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#FF6B6B';
        }

        $data = [
            'name' => $name,
            'description' => trim($post['description'] ?? '') ?: null,
            'max_days_per_year' => (int) ($post['max_days_per_year'] ?? 0),
            'is_paid' => !empty($post['is_paid']) ? 1 : 0,
            'color' => $color,
        ];

        return [$data, $errors];
    }
}
