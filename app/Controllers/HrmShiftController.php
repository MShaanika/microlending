<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmShift;

class HrmShiftController extends Controller
{
    private HrmShift $shifts;

    public function __construct()
    {
        $this->shifts = new HrmShift();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $search = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir = (string) ($_GET['dir'] ?? 'asc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->shifts->paginated($search, $sort, $dir, $page, $perPage);

        $this->view('hrm/shifts/index', [
            'title' => 'Shifts',
            'shifts' => $result['rows'],
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
        $this->view('hrm/shifts/create', [
            'title' => 'Add Shift',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/shifts/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/shifts/create', [
                'title' => 'Add Shift',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->shifts->create(array_merge($data, [
            'is_active' => 1,
            'created_by' => Auth::user()['id'] ?? null,
        ]));

        Audit::log('Create', 'HRM', 'Created shift #' . $id . ' - ' . $data['shift_name']);
        Session::flash('success', 'Shift created.');
        $this->redirect('/hrm/shifts');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $shift = $this->shifts->find($id);
        if (!$shift) {
            Session::flash('error', 'Shift not found.');
            $this->redirect('/hrm/shifts');
            return;
        }
        $this->view('hrm/shifts/edit', [
            'title' => 'Edit Shift',
            'shift' => $shift,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/shifts/' . $id . '/edit');
            return;
        }

        $shift = $this->shifts->find($id);
        if (!$shift) {
            Session::flash('error', 'Shift not found.');
            $this->redirect('/hrm/shifts');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/shifts/edit', [
                'title' => 'Edit Shift',
                'shift' => array_merge($shift, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->shifts->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated shift #' . $id . ' - ' . $data['shift_name']);
        Session::flash('success', 'Shift updated.');
        $this->redirect('/hrm/shifts');
    }

    public function toggleActive(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/shifts');
            return;
        }

        $shift = $this->shifts->find($id);
        if (!$shift) {
            Session::flash('error', 'Shift not found.');
            $this->redirect('/hrm/shifts');
            return;
        }

        $newState = (int) $shift['is_active'] === 1 ? 0 : 1;
        $this->shifts->updateRecord($id, ['is_active' => $newState]);

        Audit::log('Update', 'HRM', ($newState ? 'Activated' : 'Deactivated') . ' shift #' . $id);
        Session::flash('success', 'Shift ' . ($newState ? 'activated' : 'deactivated') . '.');
        $this->redirect('/hrm/shifts');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['shift_name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['shift_name'] = 'Shift name is required.';
        } elseif ($this->shifts->nameExists($name, $excludeId)) {
            $errors['shift_name'] = 'A shift with this name already exists.';
        }

        $data = [
            'shift_name' => $name,
            'start_time' => trim($post['start_time'] ?? '') ?: null,
            'end_time' => trim($post['end_time'] ?? '') ?: null,
            'break_start_time' => trim($post['break_start_time'] ?? '') ?: null,
            'break_end_time' => trim($post['break_end_time'] ?? '') ?: null,
            'is_night_shift' => !empty($post['is_night_shift']) ? 1 : 0,
        ];

        return [$data, $errors];
    }
}
