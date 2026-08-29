<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\ExceptionRecord;
use App\Models\User;
use App\Services\ExceptionService;

/** Exception Management Centre (Part 22-27) -- the central operational-problem queue every module's failures feed into. */
class ExceptionController extends Controller
{
    private ExceptionRecord $exceptions;

    public function __construct()
    {
        $this->exceptions = new ExceptionRecord();
    }

    public function index(): void
    {
        Auth::authorize('exceptions.view');

        $filters = [
            'module' => trim((string) ($_GET['module'] ?? '')),
            'category' => trim((string) ($_GET['category'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $this->view('exceptions/index', [
            'title' => 'Exception Management Centre',
            'counts' => $this->exceptions->counts(),
            'exceptions' => $this->exceptions->paginated($filters, $page),
            'filters' => $filters,
            'modules' => $this->exceptions->distinctModules(),
            'categories' => $this->exceptions->distinctCategories(),
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('exceptions.view');
        $exception = $this->exceptions->find((int) $id);
        if (!$exception) {
            Session::flash('error', 'Exception not found.');
            $this->redirect('/exceptions');
            return;
        }

        $this->view('exceptions/show', [
            'title' => 'Exception #' . $id,
            'exception' => $exception,
            'notes' => $this->exceptions->notes((int) $id),
            'staff' => (new User())->allActive(),
        ]);
    }

    public function assign(string $id): void
    {
        Auth::authorize('exceptions.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/exceptions/' . $id);
            return;
        }
        $ownerId = (int) ($_POST['owner_user_id'] ?? 0);
        if (!$ownerId) {
            Session::flash('error', 'Choose someone to assign this to.');
            $this->redirect('/exceptions/' . $id);
            return;
        }
        ExceptionService::assign($id, $ownerId, (int) (Auth::user()['id'] ?? 0));
        Session::flash('success', 'Assigned.');
        $this->redirect('/exceptions/' . $id);
    }

    public function investigate(string $id): void
    {
        Auth::authorize('exceptions.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/exceptions/' . $id);
            return;
        }
        ExceptionService::investigate($id, (int) (Auth::user()['id'] ?? 0));
        Session::flash('success', 'Marked as investigating.');
        $this->redirect('/exceptions/' . $id);
    }

    public function addNote(string $id): void
    {
        Auth::authorize('exceptions.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/exceptions/' . $id);
            return;
        }
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') {
            Session::flash('error', 'Note cannot be empty.');
            $this->redirect('/exceptions/' . $id);
            return;
        }
        ExceptionService::addNote($id, (int) (Auth::user()['id'] ?? 0), $note);
        Session::flash('success', 'Note added.');
        $this->redirect('/exceptions/' . $id);
    }

    public function resolve(string $id): void
    {
        Auth::authorize('exceptions.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/exceptions/' . $id);
            return;
        }

        $status = in_array($_POST['status'] ?? '', ['RESOLVED', 'ACCEPTED_RISK', 'CLOSED'], true) ? $_POST['status'] : 'RESOLVED';
        $resolution = trim((string) ($_POST['resolution'] ?? ''));
        if ($resolution === '') {
            Session::flash('error', 'A resolution summary is required.');
            $this->redirect('/exceptions/' . $id);
            return;
        }
        $rootCause = trim((string) ($_POST['root_cause'] ?? '')) ?: null;

        ExceptionService::resolve($id, $status, $resolution, $rootCause, (int) (Auth::user()['id'] ?? 0));
        Session::flash('success', 'Exception resolved.');
        $this->redirect('/exceptions/' . $id);
    }

    public function reopen(string $id): void
    {
        Auth::authorize('exceptions.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/exceptions/' . $id);
            return;
        }
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'Reopened by administrator';
        ExceptionService::reopen($id, (int) (Auth::user()['id'] ?? 0), $reason);
        Session::flash('success', 'Exception reopened.');
        $this->redirect('/exceptions/' . $id);
    }
}
