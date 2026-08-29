<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\SystemError;

/** Error Tracking dashboard (Part 4-6) -- what App\Core\ErrorHandler/ErrorTrackingService capture. */
class ErrorTrackingController extends Controller
{
    private SystemError $errors;

    public function __construct()
    {
        $this->errors = new SystemError();
    }

    public function index(): void
    {
        Auth::authorize('errors.view');

        $filters = [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
            'module' => trim((string) ($_GET['module'] ?? '')),
            'correlation_id' => trim((string) ($_GET['correlation_id'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $this->view('errors/index', [
            'title' => 'Error Tracking',
            'counts' => $this->errors->counts(),
            'mostFrequent' => $this->errors->mostFrequent(5),
            'errors' => $this->errors->paginated($filters, $page),
            'filters' => $filters,
            'modules' => $this->errors->distinctModules(),
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('errors.view');
        $error = $this->errors->find((int) $id);
        if (!$error) {
            Session::flash('error', 'Error record not found.');
            $this->redirect('/errors');
            return;
        }
        $this->view('errors/show', ['title' => 'Error #' . $id, 'error' => $error]);
    }

    public function updateStatus(string $id): void
    {
        Auth::authorize('errors.view');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/errors/' . $id);
            return;
        }

        $status = in_array($_POST['status'] ?? '', ['INVESTIGATING', 'RESOLVED', 'IGNORED', 'REOPENED'], true) ? $_POST['status'] : null;
        if (!$status) {
            Session::flash('error', 'Invalid status.');
            $this->redirect('/errors/' . $id);
            return;
        }

        $this->errors->updateStatus($id, $status, Auth::user()['id'] ?? null);
        Audit::log('Update', 'Platform', "Error #$id marked $status", ['error_id' => $id]);

        Session::flash('success', 'Updated.');
        $this->redirect('/errors/' . $id);
    }
}
