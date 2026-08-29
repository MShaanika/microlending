<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\AuditLog;
use App\Models\User;

class AuditLogController extends Controller
{
    private AuditLog $auditLogs;
    private User $users;

    public function __construct()
    {
        $this->auditLogs = new AuditLog();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('admin.audit');

        $filters = [
            'module_name' => trim((string) ($_GET['module_name'] ?? '')),
            'action' => trim((string) ($_GET['action'] ?? '')),
            'user_id' => (int) ($_GET['user_id'] ?? 0),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
            'search' => trim((string) ($_GET['search'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, (int) ($_GET['per_page'] ?? 25));

        $result = $this->auditLogs->paginated($filters, $page, $perPage);

        $this->view('settings/audit_log/index', [
            'title' => 'Activity Log',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'totalPages' => $result['totalPages'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
            'modules' => $this->auditLogs->distinctModules(),
            'actions' => $this->auditLogs->distinctActions(),
            'users' => $this->users->allActive(),
        ]);
    }
}
