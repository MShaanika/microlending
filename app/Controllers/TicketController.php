<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\SupportSession;
use App\Models\SupportTicket;
use App\Models\SupportTicketComment;
use App\Models\User;

class TicketController extends Controller
{
    private const SESSION_DURATION_HOURS = 4;

    private SupportTicket $tickets;
    private SupportTicketComment $comments;
    private SupportSession $sessions;
    private Branch $branches;
    private User $users;

    public function __construct()
    {
        $this->tickets = new SupportTicket();
        $this->comments = new SupportTicketComment();
        $this->sessions = new SupportSession();
        $this->branches = new Branch();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('tickets.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $branchId = Auth::can('tickets.manage') ? null : (Auth::branchId() ?? 0);

        $this->view('tickets/index', [
            'title' => 'Support Tickets',
            'tickets' => $this->tickets->paginated($status, $branchId),
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('tickets.view');
        $this->view('tickets/create', [
            'title' => 'Raise Support Ticket',
            'old' => [],
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('tickets.view');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/tickets/create');
        }

        $errors = [];
        foreach (['subject', 'description'] as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                $errors[$field] = 'This field is required.';
            }
        }

        if (!empty($errors)) {
            $this->view('tickets/create', [
                'title' => 'Raise Support Ticket',
                'old' => $_POST,
                'errors' => $errors,
            ]);
            return;
        }

        $user = Auth::user();
        $branchId = Auth::branchId();
        if ($branchId === null) {
            Session::flash('error', 'A ticket must be raised from a branch account.');
            $this->redirect('/tickets/create');
        }

        $ticketId = $this->tickets->create([
            'ticket_no' => generate_reference('TCK'),
            'branch_id' => $branchId,
            'raised_by' => $user['id'],
            'subject' => trim($_POST['subject']),
            'description' => trim($_POST['description']),
            'priority' => in_array($_POST['priority'] ?? '', ['Low', 'Medium', 'High', 'Urgent'], true) ? $_POST['priority'] : 'Medium',
            'status' => 'Open',
        ]);

        Audit::log('Create', 'Tickets', "Raised ticket #$ticketId");
        Session::flash('success', 'Ticket raised. Support will follow up shortly.');
        $this->redirect('/tickets/' . $ticketId);
    }

    private function assertAccess(?array $ticket): void
    {
        if (!$ticket) {
            Session::flash('error', 'Ticket not found.');
            $this->redirect('/tickets');
        }
        if (Auth::can('tickets.manage')) {
            return;
        }
        if ((int) $ticket['branch_id'] !== (int) Auth::branchId()) {
            Session::flash('error', 'Ticket not found.');
            $this->redirect('/tickets');
        }
    }

    public function show(string $id): void
    {
        Auth::authorize('tickets.view');
        $id = (int) $id;
        $ticket = $this->tickets->find($id);
        $this->assertAccess($ticket);

        $this->view('tickets/show', [
            'title' => 'Ticket ' . $ticket['ticket_no'],
            'ticket' => $ticket,
            'comments' => $this->comments->forTicket($id),
            'sessionHistory' => $this->sessions->forTicket($id),
            'activeSession' => $this->sessions->activeForTicket($id),
            'developers' => Auth::can('tickets.manage') ? $this->users->usersWithPermission('tickets.support_session') : [],
        ]);
    }

    public function comment(string $id): void
    {
        Auth::authorize('tickets.view');
        $id = (int) $id;
        $ticket = $this->tickets->find($id);
        $this->assertAccess($ticket);

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/tickets/' . $id);
        }

        $text = trim((string) ($_POST['comment'] ?? ''));
        if ($text === '') {
            Session::flash('error', 'Comment cannot be empty.');
            $this->redirect('/tickets/' . $id);
        }

        $this->comments->create([
            'ticket_id' => $id,
            'user_id' => Auth::user()['id'],
            'comment' => $text,
        ]);

        Audit::log('Comment', 'Tickets', "Commented on ticket #$id");
        Session::flash('success', 'Comment added.');
        $this->redirect('/tickets/' . $id);
    }

    public function assign(string $id): void
    {
        Auth::authorize('tickets.manage');
        $id = (int) $id;
        $ticket = $this->tickets->find($id);
        if (!$ticket) {
            Session::flash('error', 'Ticket not found.');
            $this->redirect('/tickets');
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/tickets/' . $id);
        }

        $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
        $this->tickets->updateRecord($id, [
            'assigned_to' => $assignedTo ?: null,
            'status' => $ticket['status'] === 'Open' ? 'In Progress' : $ticket['status'],
        ]);

        Audit::log('Assign', 'Tickets', "Assigned ticket #$id");
        Session::flash('success', 'Ticket assigned.');
        $this->redirect('/tickets/' . $id);
    }

    public function updateStatus(string $id): void
    {
        Auth::authorize('tickets.manage');
        $id = (int) $id;
        $ticket = $this->tickets->find($id);
        if (!$ticket) {
            Session::flash('error', 'Ticket not found.');
            $this->redirect('/tickets');
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/tickets/' . $id);
        }

        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['Open', 'In Progress', 'Resolved', 'Closed'], true)) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/tickets/' . $id);
        }

        $data = ['status' => $status];
        if ($status === 'Resolved') {
            $data['resolution_notes'] = trim((string) ($_POST['resolution_notes'] ?? '')) ?: null;
            $data['resolved_at'] = date('Y-m-d H:i:s');
        }
        $this->tickets->updateRecord($id, $data);

        // Closing a ticket must cascade to end any active support session tied to it.
        if ($status === 'Closed') {
            $this->sessions->endActiveForTicket($id, 'TicketClosed');
            $activeSession = Auth::activeSupportSession();
            if ($activeSession && (int) $activeSession['ticket_id'] === $id) {
                Auth::clearSupportSession();
            }
        }

        Audit::log('UpdateStatus', 'Tickets', "Ticket #$id status set to $status");
        Session::flash('success', 'Ticket status updated.');
        $this->redirect('/tickets/' . $id);
    }

    public function startSupportSession(string $id): void
    {
        Auth::authorize('tickets.support_session');
        $id = (int) $id;
        $ticket = $this->tickets->find($id);
        if (!$ticket) {
            Session::flash('error', 'Ticket not found.');
            $this->redirect('/tickets');
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/tickets/' . $id);
        }

        $userId = (int) Auth::user()['id'];
        // Only one active session per developer at a time.
        $this->sessions->endActiveForDeveloper($userId, 'SupersededByNewSession');

        $startedAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::SESSION_DURATION_HOURS . ' hours'));

        $sessionId = $this->sessions->create([
            'ticket_id' => $id,
            'developer_id' => $userId,
            'branch_id' => (int) $ticket['branch_id'],
            'started_at' => $startedAt,
            'expires_at' => $expiresAt,
        ]);

        Auth::setSupportSession([
            'session_id' => $sessionId,
            'ticket_id' => $id,
            'branch_id' => (int) $ticket['branch_id'],
            'expires_at' => $expiresAt,
        ]);

        if ($ticket['status'] === 'Open') {
            $this->tickets->updateRecord($id, ['status' => 'In Progress']);
        }

        Audit::log('StartSupportSession', 'Tickets', "Started support session #$sessionId on ticket #$id, branch #{$ticket['branch_id']}, expires $expiresAt");
        Session::flash('success', 'Support session started. You now have access to this branch\'s data until ' . $expiresAt . '.');
        $this->redirect('/tickets/' . $id);
    }

    public function endSupportSession(): void
    {
        Auth::authorize('tickets.support_session');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/dashboard');
        }

        $active = Auth::activeSupportSession();
        if ($active) {
            $this->sessions->end((int) $active['session_id'], 'ManuallyEnded');
            Audit::log('EndSupportSession', 'Tickets', "Manually ended support session #{$active['session_id']} on ticket #{$active['ticket_id']}");
        }
        Auth::clearSupportSession();

        Session::flash('success', 'Support session ended.');
        $this->redirect($active ? '/tickets/' . $active['ticket_id'] : '/dashboard');
    }
}
