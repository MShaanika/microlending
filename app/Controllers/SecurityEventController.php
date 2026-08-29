<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\SecurityEventLog;
use App\Models\User;

class SecurityEventController extends Controller
{
    private SecurityEventLog $events;
    private User $users;

    public function __construct()
    {
        $this->events = new SecurityEventLog();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('security.view');

        $filters = [
            'event_type' => trim((string) ($_GET['event_type'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
            'ip' => trim((string) ($_GET['ip'] ?? '')),
            'user_id' => (int) ($_GET['user_id'] ?? 0),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
            'search' => trim((string) ($_GET['search'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 25));

        $result = $this->events->paginated($filters, $page, $perPage);

        $this->view('security/events/index', [
            'title' => 'Security Events',
            'events' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
            'eventTypes' => $this->events->distinctEventTypes(),
            'users' => $this->users->allActive(),
        ]);
    }
}
