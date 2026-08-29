<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\ApprovalRequest;
use App\Models\Delegation;
use App\Models\SlaInstance;
use App\Services\ApprovalService;

class ApprovalController extends Controller
{
    private ApprovalRequest $requests;

    public function __construct()
    {
        $this->requests = new ApprovalRequest();
    }

    /** "My Approvals" (Part 11) -- tabs: Awaiting My Approval, Submitted by Me. Awaiting-me combines the user's own role permissions with anything currently delegated to them. */
    public function index(): void
    {
        Auth::authorize('approvals.view');
        $userId = (int) (Auth::user()['id'] ?? 0);
        $tab = ($_GET['tab'] ?? 'awaiting') === 'submitted' ? 'submitted' : 'awaiting';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $ownPermissions = Auth::user()['permissions'] ?? [];
        $delegatedPermissions = (new Delegation())->activePermissionKeysFor($userId);
        $eligiblePermissions = array_values(array_unique(array_merge($ownPermissions, $delegatedPermissions)));

        $awaiting = $this->requests->pendingForPermissions($eligiblePermissions, $userId, $tab === 'awaiting' ? $page : 1);
        $submitted = $this->requests->submittedByUser($userId, $tab === 'submitted' ? $page : 1);

        $this->view('approvals/index', [
            'title' => 'My Approvals',
            'tab' => $tab,
            'awaiting' => $awaiting,
            'submitted' => $submitted,
            'page' => $page,
        ]);
    }

    public function show(string $id): void
    {
        Auth::authorize('approvals.view');
        $request = $this->requests->find((int) $id);
        if (!$request) {
            Session::flash('error', 'Approval request not found.');
            $this->redirect('/approvals');
            return;
        }

        $this->view('approvals/show', [
            'title' => $request['title'],
            'req' => $request,
            'timeline' => $this->requests->timeline((int) $id),
            'currentStep' => $this->requests->currentStep((int) $id),
            'slaInstance' => (new SlaInstance())->findOpenByResource('approval_request', (int) $id),
        ]);
    }

    public function approve(string $id): void
    {
        Auth::authorize('approvals.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/approvals/' . $id);
            return;
        }

        try {
            ApprovalService::approve($id, trim((string) ($_POST['comments'] ?? '')) ?: null);
            Session::flash('success', 'Approved.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect('/approvals/' . $id);
    }

    public function reject(string $id): void
    {
        Auth::authorize('approvals.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/approvals/' . $id);
            return;
        }

        try {
            ApprovalService::reject($id, trim((string) ($_POST['comments'] ?? '')));
            Session::flash('success', 'Rejected.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect('/approvals/' . $id);
    }

    public function returnForCorrection(string $id): void
    {
        Auth::authorize('approvals.approve');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/approvals/' . $id);
            return;
        }

        try {
            ApprovalService::returnForCorrection($id, trim((string) ($_POST['comments'] ?? '')));
            Session::flash('success', 'Returned to the requester for correction.');
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        }
        $this->redirect('/approvals/' . $id);
    }
}
