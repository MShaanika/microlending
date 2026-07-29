<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEventType;

class HrmEventTypeController extends Controller
{
    private HrmEventType $types;

    public function __construct()
    {
        $this->types = new HrmEventType();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $this->view('hrm/event-types/index', [
            'title' => 'Event Types',
            'types' => $this->types->allTypes(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/event-types/create', [
            'title' => 'Add Event Type',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/event-types/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/event-types/create', [
                'title' => 'Add Event Type',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $id = $this->types->create(array_merge($data, ['created_by' => Auth::user()['id'] ?? null]));

        Audit::log('Create', 'HRM', 'Created event type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Event type created.');
        $this->redirect('/hrm/event-types');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Event type not found.');
            $this->redirect('/hrm/event-types');
            return;
        }
        $this->view('hrm/event-types/edit', [
            'title' => 'Edit Event Type',
            'type' => $type,
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/event-types/' . $id . '/edit');
            return;
        }

        $type = $this->types->find($id);
        if (!$type) {
            Session::flash('error', 'Event type not found.');
            $this->redirect('/hrm/event-types');
            return;
        }

        [$data, $errors] = $this->validate($_POST, $id);

        if (!empty($errors)) {
            $this->view('hrm/event-types/edit', [
                'title' => 'Edit Event Type',
                'type' => array_merge($type, $_POST),
                'errors' => $errors,
            ]);
            return;
        }

        $this->types->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated event type #' . $id . ' - ' . $data['name']);
        Session::flash('success', 'Event type updated.');
        $this->redirect('/hrm/event-types');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/event-types');
            return;
        }

        if ($this->types->inUseCount($id) > 0) {
            Session::flash('error', 'This event type is used by existing events and cannot be deleted.');
            $this->redirect('/hrm/event-types');
            return;
        }

        $this->types->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted event type #' . $id);
        Session::flash('success', 'Event type deleted.');
        $this->redirect('/hrm/event-types');
    }

    private function validate(array $post, ?int $excludeId = null): array
    {
        $name = trim($post['name'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif ($this->types->nameExists($name, $excludeId)) {
            $errors['name'] = 'An event type with this name already exists.';
        }

        $data = ['name' => $name];

        return [$data, $errors];
    }
}
