<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\SecurityIncident;
use App\Models\User;

class SecurityIncidentController extends Controller
{
    private SecurityIncident $incidents;
    private User $users;

    public function __construct()
    {
        $this->incidents = new SecurityIncident();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('security.view');

        $filters = [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 25));

        $result = $this->incidents->paginated($filters, $page, $perPage);

        $this->view('security/incidents/index', [
            'title' => 'Security Incidents',
            'incidents' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('security.view');
        $incident = $this->incidents->find((int) $id);
        if (!$incident) {
            Session::flash('error', 'Incident not found.');
            $this->redirect('/security/incidents');
            return;
        }

        $this->view('security/incidents/show', [
            'title' => $incident['title'],
            'incident' => $incident,
            'events' => $this->incidents->events((int) $id),
            'staffUsers' => $this->users->allActive(),
        ]);
    }

    /** Status transitions, assignment, and resolution notes -- all one form on the detail page. */
    public function update(string $id): void
    {
        Auth::authorize('security.incidents.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/security/incidents/' . $id);
            return;
        }

        $incident = $this->incidents->find($id);
        if (!$incident) {
            Session::flash('error', 'Incident not found.');
            $this->redirect('/security/incidents');
            return;
        }

        $status = trim((string) ($_POST['status'] ?? $incident['status']));
        $validStatuses = ['Open', 'Investigating', 'Contained', 'Resolved', 'False Positive', 'Closed'];
        if (!in_array($status, $validStatuses, true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/security/incidents/' . $id);
            return;
        }

        $assignedTo = ($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null;
        $notes = trim((string) ($_POST['resolution_notes'] ?? ''));
        $userId = Auth::user()['id'] ?? null;

        $data = ['status' => $status, 'assigned_to' => $assignedTo];
        if ($notes !== '') {
            $data['resolution_notes'] = $notes;
        }
        if (in_array($status, ['Resolved', 'False Positive', 'Closed'], true) && $incident['status'] !== $status) {
            $data['resolved_by'] = $userId;
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }

        $this->incidents->updateRecord($id, $data);

        // The incident's own status change is itself a security-relevant
        // administrative action -- goes to the compliance audit trail, not
        // security_events (nothing "suspicious" happened here, an admin
        // did their job).
        Audit::log(
            'Update',
            'Security',
            'Security incident #' . $id . ' status changed from ' . $incident['status'] . ' to ' . $status . ($notes !== '' ? ': ' . $notes : ''),
            ['old_status' => $incident['status'], 'new_status' => $status]
        );

        Session::flash('success', 'Incident updated.');
        $this->redirect('/security/incidents/' . $id);
    }
}
