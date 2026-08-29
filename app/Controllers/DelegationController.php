<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Branch;
use App\Models\Delegation;
use App\Models\User;
use App\Services\DelegationService;
use App\Services\SegregationOfDutyService;

class DelegationController extends Controller
{
    private Delegation $delegations;
    private User $users;

    public function __construct()
    {
        $this->delegations = new Delegation();
        $this->users = new User();
    }

    public function index(): void
    {
        Auth::authorize('delegations.view');
        $status = trim((string) ($_GET['status'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $this->view('delegations/index', [
            'title' => 'Delegation & Temporary Authority',
            'delegations' => $this->delegations->paginated(['status' => $status], $page),
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        Auth::authorize('delegations.manage');
        $this->view('delegations/create', [
            'title' => 'Create Delegation',
            'staff' => $this->users->allActive(),
            'branches' => (new Branch())->all(),
            'permissions' => $this->delegations->delegatablePermissions(),
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::authorize('delegations.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/delegations/create');
            return;
        }

        $delegatorId = (int) ($_POST['delegator_user_id'] ?? 0);
        $delegateId = (int) ($_POST['delegate_user_id'] ?? 0);
        $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
        $endsAt = trim((string) ($_POST['ends_at'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $permissionKeys = array_filter((array) ($_POST['permission_keys'] ?? []));
        $amountLimit = trim((string) ($_POST['amount_limit'] ?? '')) !== '' ? (float) $_POST['amount_limit'] : null;
        $branchId = ($_POST['branch_id'] ?? '') !== '' ? (int) $_POST['branch_id'] : null;

        $errors = [];
        if (!$delegatorId || !$delegateId) {
            $errors[] = 'Choose both a delegator and a delegate.';
        }
        if (!$startsAt || !$endsAt) {
            $errors[] = 'Start and end date/time are required.';
        } elseif (strtotime($endsAt) <= strtotime($startsAt)) {
            $errors[] = 'The end date/time must be after the start date/time.';
        }
        if (empty($permissionKeys)) {
            $errors[] = 'Select at least one specific permission to delegate.';
        }

        if ($errors) {
            $this->view('delegations/create', [
                'title' => 'Create Delegation',
                'staff' => $this->users->allActive(),
                'branches' => (new Branch())->all(),
                'permissions' => $this->delegations->delegatablePermissions(),
                'errors' => $errors,
            ]);
            return;
        }

        $scopes = array_map(static fn ($key) => [
            'permission_key' => $key,
            'module' => null,
            'amount_limit' => $amountLimit,
            'branch_id' => $branchId,
        ], $permissionKeys);

        try {
            $delegationId = DelegationService::create($delegatorId, $delegateId, $startsAt, $endsAt, $reason ?: null, $scopes, (int) (Auth::user()['id'] ?? 0));
        } catch (\RuntimeException $e) {
            $this->view('delegations/create', [
                'title' => 'Create Delegation',
                'staff' => $this->users->allActive(),
                'branches' => (new Branch())->all(),
                'permissions' => $this->delegations->delegatablePermissions(),
                'errors' => [$e->getMessage()],
            ]);
            return;
        }

        $conflicts = SegregationOfDutyService::conflictsFor($delegateId);
        if ($conflicts) {
            Session::flash('error', 'Delegation created, but it introduces a segregation-of-duty conflict: ' . $conflicts[0]['rule_name'] . '. Review before relying on it.');
        } else {
            Session::flash('success', 'Delegation created.');
        }
        $this->redirect('/delegations/' . $delegationId);
    }

    public function show(string $id): void
    {
        Auth::authorize('delegations.view');
        $delegation = $this->delegations->find((int) $id);
        if (!$delegation) {
            Session::flash('error', 'Delegation not found.');
            $this->redirect('/delegations');
            return;
        }

        $this->view('delegations/show', [
            'title' => 'Delegation #' . $id,
            'delegation' => $delegation,
            'scopes' => $this->delegations->scopesFor((int) $id),
        ]);
    }

    public function revoke(string $id): void
    {
        Auth::authorize('delegations.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/delegations/' . $id);
            return;
        }

        $delegation = $this->delegations->find($id);
        if (!$delegation || $delegation['status'] === 'Revoked') {
            Session::flash('error', 'Delegation not found or already revoked.');
            $this->redirect('/delegations');
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'Revoked by administrator';
        DelegationService::revoke($id, (int) (Auth::user()['id'] ?? 0), $reason);

        Session::flash('success', 'Delegation revoked.');
        $this->redirect('/delegations/' . $id);
    }
}
