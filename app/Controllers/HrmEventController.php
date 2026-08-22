<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmDepartment;
use App\Models\HrmEvent;
use App\Models\HrmEventType;
use DateTime;

class HrmEventController extends Controller
{
    private HrmEvent $events;
    private HrmEventType $eventTypes;
    private HrmDepartment $departments;

    public function __construct()
    {
        $this->events = new HrmEvent();
        $this->eventTypes = new HrmEventType();
        $this->departments = new HrmDepartment();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');

        $filters = [
            'status' => $_GET['status'] ?? '',
            'department_id' => $_GET['department_id'] ?? '',
            'search' => trim((string) ($_GET['q'] ?? '')),
        ];
        $sort = (string) ($_GET['sort'] ?? 'start_date');
        $dir = (string) ($_GET['dir'] ?? 'desc');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

        $result = $this->events->paginated($filters, $sort, $dir, $page, $perPage);

        $this->view('hrm/events/index', [
            'title' => 'Events',
            'events' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'departments' => $this->departments->allDepartments(true),
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/events/create', [
            'title' => 'New Event',
            'eventTypes' => $this->eventTypes->allTypes(),
            'departments' => $this->departments->allDepartments(true),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/events/create');
            return;
        }

        [$data, $departmentIds, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/events/create', [
                'title' => 'New Event',
                'eventTypes' => $this->eventTypes->allTypes(),
                'departments' => $this->departments->allDepartments(true),
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $data['status'] = 'Pending';
        $data['created_by'] = Auth::user()['id'] ?? null;
        $id = $this->events->create($data);
        $this->events->syncDepartments($id, $departmentIds);

        Audit::log('Create', 'HRM', 'Event #' . $id . ' created - ' . $data['title']);
        Session::flash('success', 'Event submitted for approval.');
        $this->redirect('/hrm/events');
    }

    public function show(int $id): void
    {
        Auth::authorize('hrm.view');
        $event = $this->events->find($id);
        if (!$event) {
            Session::flash('error', 'Event not found.');
            $this->redirect('/hrm/events');
            return;
        }
        $this->view('hrm/events/show', [
            'title' => 'Event',
            'event' => $event,
            'departmentNames' => $this->events->departmentNamesFor($id),
        ]);
    }

    public function approve(int $id): void
    {
        $this->decide($id, 'Approved');
    }

    public function reject(int $id): void
    {
        $this->decide($id, 'Rejected');
    }

    private function decide(int $id, string $status): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/events/' . $id);
            return;
        }

        $event = $this->events->find($id);
        if (!$event || $event['status'] !== 'Pending') {
            Session::flash('error', 'Only pending events can be decided.');
            $this->redirect('/hrm/events');
            return;
        }

        $this->events->updateRecord($id, [
            'status' => $status,
            'approved_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'HRM', 'Event #' . $id . ' ' . strtolower($status));
        Session::flash('success', 'Event ' . strtolower($status) . '.');
        $this->redirect('/hrm/events/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/events');
            return;
        }

        $this->events->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted event #' . $id);
        Session::flash('success', 'Event deleted.');
        $this->redirect('/hrm/events');
    }

    private function validate(array $post): array
    {
        $errors = [];
        $title = trim($post['title'] ?? '');
        $startDate = trim($post['start_date'] ?? '');
        $endDate = trim($post['end_date'] ?? '');
        $location = trim($post['location'] ?? '');
        $departmentIds = array_filter(array_map('intval', $post['department_ids'] ?? []));

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($startDate === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($endDate === '') {
            $errors['end_date'] = 'End date is required.';
        } elseif ($startDate !== '' && new DateTime($endDate) < new DateTime($startDate)) {
            $errors['end_date'] = 'End date cannot be before the start date.';
        }
        if ($location === '') {
            $errors['location'] = 'Location is required.';
        }
        if (empty($departmentIds)) {
            $errors['department_ids'] = 'Select at least one department.';
        }

        $data = [
            'title' => $title,
            'event_type_id' => !empty($post['event_type_id']) ? (int) $post['event_type_id'] : null,
            'start_date' => $startDate ?: null,
            'end_date' => $endDate ?: null,
            'start_time' => trim($post['start_time'] ?? '') ?: null,
            'end_time' => trim($post['end_time'] ?? '') ?: null,
            'location' => $location,
            'color' => trim($post['color'] ?? '') ?: null,
            'description' => trim($post['description'] ?? '') ?: null,
        ];

        return [$data, $departmentIds, $errors];
    }
}
