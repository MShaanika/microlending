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
        $this->view('performance/goal-types/index', [
            'title' => 'Goal Types',
            'types' => $this->types->allTypes(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('performance.manage');
        $this->view('performance/goal-types/create', [
            'title' => 'Add Goal Type',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/goal-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('performance/goal-types/create', [
                'title' => 'Add Goal Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'Performance', 'Created goal type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Goal type created.');
        $this->redirect('/performance/goal-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('performance.manage');
        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Goal type not found.');
            $this->redirect('/performance/goal-types');
            return;
        }
        $this->view('performance/goal-types/edit', [
            'title' => 'Edit Goal Type',
            'type' => $type,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('performance.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/performance/goal-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Goal type not found.');
            $this->redirect('/performance/goal-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('performance/goal-types/edit', [
                'title' => 'Edit Goal Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'Performance', 'Updated goal type #' . $id . ' - ' . $data['name']);
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
