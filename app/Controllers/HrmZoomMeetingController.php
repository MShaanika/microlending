<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HrmEmployee;
use App\Models\HrmZoomMeeting;
use App\Models\HrmZoomSetting;
use App\Models\User;
use App\Services\ZoomApiService;

class HrmZoomMeetingController extends Controller
{
    private const STATUSES = ['Scheduled', 'Started', 'Ended', 'Cancelled'];

    private HrmZoomMeeting $meetings;
    private HrmZoomSetting $settings;
    private User $users;
    private HrmEmployee $employees;

    public function __construct()
    {
        $this->meetings = new HrmZoomMeeting();
        $this->settings = new HrmZoomSetting();
        $this->users = new User();
        $this->employees = new HrmEmployee();
    }

    public function index(): void
    {
        Auth::authorize('hrm.view');
        $filters = [
            'status' => $_GET['status'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];
        $this->view('hrm/zoom-meetings/index', [
            'title' => 'Zoom Meetings',
            'meetings' => $this->meetings->allMeetings($filters),
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'zoomEnabled' => $this->settings->isEnabled(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('hrm.manage');
        $this->view('hrm/zoom-meetings/create', [
            'title' => 'Schedule Zoom Meeting',
            'users' => $this->users->allActive(),
            'employees' => $this->employees->allEmployees(['status' => 'Active']),
            'zoomEnabled' => $this->settings->isEnabled(),
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/zoom-meetings/create');
            return;
        }

        if (!$this->settings->isEnabled()) {
            Session::flash('error', 'Zoom meeting integration is disabled. Configure it under Zoom Settings first.');
            $this->redirect('/hrm/zoom-meetings/create');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/zoom-meetings/create', [
                'title' => 'Schedule Zoom Meeting',
                'users' => $this->users->allActive(),
                'employees' => $this->employees->allEmployees(['status' => 'Active']),
                'zoomEnabled' => true,
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        try {
            $zoom = new ZoomApiService();
            $zoomResponse = $zoom->createMeeting($data);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/hrm/zoom-meetings/create');
            return;
        }

        $data['meeting_id'] = $zoomResponse['id'] ?? null;
        $data['start_url'] = $zoomResponse['start_url'] ?? null;
        $data['join_url'] = $zoomResponse['join_url'] ?? null;
        $data['status'] = 'Scheduled';
        $data['created_by'] = Auth::user()['id'] ?? null;

        $id = $this->meetings->create($data);

        Audit::log('Create', 'HRM', 'Scheduled Zoom meeting #' . $id . ' - ' . $data['title']);
        Session::flash('success', 'Zoom meeting scheduled.');
        $this->redirect('/hrm/zoom-meetings');
    }

    public function edit(int $id): void
    {
        Auth::authorize('hrm.manage');
        $meeting = $this->meetings->find($id);
        if (!$meeting) {
            Session::flash('error', 'Meeting not found.');
            $this->redirect('/hrm/zoom-meetings');
            return;
        }
        if ($meeting['status'] !== 'Scheduled') {
            Session::flash('error', 'Only meetings still in Scheduled status can be edited.');
            $this->redirect('/hrm/zoom-meetings');
            return;
        }
        $this->view('hrm/zoom-meetings/edit', [
            'title' => 'Edit Zoom Meeting',
            'meeting' => $meeting,
            'selectedParticipants' => json_decode($meeting['participants'] ?? '[]', true) ?: [],
            'users' => $this->users->allActive(),
            'employees' => $this->employees->allEmployees(['status' => 'Active']),
            'zoomEnabled' => $this->settings->isEnabled(),
            'errors' => [],
        ]);
    }

    public function update(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/zoom-meetings/' . $id . '/edit');
            return;
        }

        $meeting = $this->meetings->find($id);
        if (!$meeting || $meeting['status'] !== 'Scheduled') {
            Session::flash('error', 'This meeting cannot be edited.');
            $this->redirect('/hrm/zoom-meetings');
            return;
        }

        if (!$this->settings->isEnabled()) {
            Session::flash('error', 'Zoom meeting integration is disabled.');
            $this->redirect('/hrm/zoom-meetings/' . $id . '/edit');
            return;
        }

        [$data, $errors] = $this->validate($_POST);

        if (!empty($errors)) {
            $this->view('hrm/zoom-meetings/edit', [
                'title' => 'Edit Zoom Meeting',
                'meeting' => array_merge($meeting, $_POST),
                'selectedParticipants' => array_map('intval', $_POST['participants'] ?? []),
                'users' => $this->users->allActive(),
                'employees' => $this->employees->allEmployees(['status' => 'Active']),
                'zoomEnabled' => true,
                'errors' => $errors,
            ]);
            return;
        }

        if (!empty($meeting['meeting_id'])) {
            try {
                $zoom = new ZoomApiService();
                $zoomResponse = $zoom->updateMeeting($meeting['meeting_id'], $data);
                $data['start_url'] = $zoomResponse['start_url'] ?? $meeting['start_url'];
                $data['join_url'] = $zoomResponse['join_url'] ?? $meeting['join_url'];
            } catch (\Throwable $e) {
                Session::flash('error', $e->getMessage());
                $this->redirect('/hrm/zoom-meetings/' . $id . '/edit');
                return;
            }
        }

        $this->meetings->updateRecord($id, $data);

        Audit::log('Update', 'HRM', 'Updated Zoom meeting #' . $id . ' - ' . $data['title']);
        Session::flash('success', 'Zoom meeting updated.');
        $this->redirect('/hrm/zoom-meetings');
    }

    public function updateStatus(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/zoom-meetings');
            return;
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/hrm/zoom-meetings');
            return;
        }

        $this->meetings->updateRecord($id, ['status' => $status]);
        Audit::log('Update', 'HRM', 'Updated Zoom meeting #' . $id . ' status to ' . $status);
        Session::flash('success', 'Meeting status updated.');
        $this->redirect('/hrm/zoom-meetings');
    }

    public function delete(int $id): void
    {
        Auth::authorize('hrm.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/hrm/zoom-meetings');
            return;
        }

        $meeting = $this->meetings->find($id);
        if ($meeting && !empty($meeting['meeting_id']) && $this->settings->isEnabled()) {
            try {
                (new ZoomApiService())->deleteMeeting($meeting['meeting_id']);
            } catch (\Throwable $e) {
                // Meeting may already be gone on Zoom's side -- proceed with local deletion regardless.
            }
        }

        $this->meetings->delete($id);
        Audit::log('Delete', 'HRM', 'Deleted Zoom meeting #' . $id);
        Session::flash('success', 'Zoom meeting deleted.');
        $this->redirect('/hrm/zoom-meetings');
    }

    private function validate(array $post): array
    {
        $title = trim($post['title'] ?? '');
        $startTime = $post['start_time'] ?? '';
        $errors = [];

        if ($title === '') {
            $errors['title'] = 'Title is required.';
        }
        if ($startTime === '') {
            $errors['start_time'] = 'Start date/time is required.';
        }

        $participantIds = array_filter(array_map('intval', $post['participants'] ?? []));

        $data = [
            'title' => $title,
            'description' => trim($post['description'] ?? '') ?: null,
            'meeting_password' => trim($post['meeting_password'] ?? '') ?: null,
            'start_time' => $startTime ? str_replace('T', ' ', $startTime) . ':00' : null,
            'duration' => !empty($post['duration']) ? (int) $post['duration'] : 30,
            'host_video' => !empty($post['host_video']) ? 1 : 0,
            'participant_video' => !empty($post['participant_video']) ? 1 : 0,
            'waiting_room' => !empty($post['waiting_room']) ? 1 : 0,
            'recording' => !empty($post['recording']) ? 1 : 0,
            'participants' => json_encode(array_values($participantIds)),
            'host_id' => !empty($post['host_id']) ? (int) $post['host_id'] : null,
        ];

        return [$data, $errors];
    }
}
