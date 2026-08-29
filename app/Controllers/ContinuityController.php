<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\BackupRun;
use App\Models\ContinuityPlan;
use App\Services\BackupService;
use App\Services\HealthCheckService;

/** Business Continuity & Disaster Recovery (Part 7) -- backup status/history plus admin-authored recovery plans. RTO/RPO targets are never invented here; a plan stays incomplete until an admin fills them in. */
class ContinuityController extends Controller
{
    private BackupRun $backups;
    private ContinuityPlan $plans;

    public function __construct()
    {
        $this->backups = new BackupRun();
        $this->plans = new ContinuityPlan();
    }

    public function index(): void
    {
        Auth::authorize('continuity.view');
        $this->view('continuity/index', [
            'title' => 'Business Continuity',
            'backupStatus' => HealthCheckService::checkBackup(),
            'recentBackups' => $this->backups->latest(10),
            'plans' => $this->plans->allPlans(),
        ]);
    }

    /** Triggers a real, synchronous backup -- the same BackupService::run() the daily cron uses. */
    public function runBackupNow(): void
    {
        Auth::authorize('continuity.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/continuity');
            return;
        }

        $result = BackupService::run('manual', Auth::user()['id'] ?? null);
        Audit::log('Run', 'Continuity', $result['success'] ? 'Ran a manual backup successfully.' : 'Manual backup failed: ' . $result['error']);

        if ($result['success']) {
            Session::flash('success', 'Backup completed successfully.');
        } else {
            Session::flash('error', 'Backup failed: ' . $result['error']);
        }
        $this->redirect('/continuity');
    }

    public function plans(): void
    {
        Auth::authorize('continuity.view');
        $this->view('continuity/plans', ['title' => 'Continuity Plans', 'plans' => $this->plans->allPlans()]);
    }

    public function createPlan(): void
    {
        Auth::authorize('continuity.manage');
        $this->view('continuity/plan_form', ['title' => 'New Continuity Plan', 'plan' => null]);
    }

    public function storePlan(): void
    {
        Auth::authorize('continuity.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/continuity/plans/create');
            return;
        }

        $name = trim((string) ($_POST['plan_name'] ?? ''));
        $scope = trim((string) ($_POST['scope_description'] ?? ''));
        if ($name === '' || $scope === '') {
            Session::flash('error', 'A plan name and scope are required.');
            $this->redirect('/continuity/plans/create');
            return;
        }

        $id = $this->plans->create(array_merge($this->collectFromRequest(), [
            'plan_name' => $name,
            'scope_description' => $scope,
            'created_by' => Auth::user()['id'] ?? null,
        ]));

        Audit::log('Create', 'Continuity', "Created continuity plan '$name'", ['plan_id' => $id]);
        Session::flash('success', 'Continuity plan created.');
        $this->redirect('/continuity/plans');
    }

    public function editPlan(string $id): void
    {
        Auth::authorize('continuity.manage');
        $plan = $this->plans->find((int) $id);
        if (!$plan) {
            Session::flash('error', 'Plan not found.');
            $this->redirect('/continuity/plans');
            return;
        }
        $this->view('continuity/plan_form', ['title' => 'Edit Continuity Plan', 'plan' => $plan]);
    }

    public function updatePlan(string $id): void
    {
        Auth::authorize('continuity.manage');
        $id = (int) $id;
        $plan = $this->plans->find($id);
        if (!$plan) {
            Session::flash('error', 'Plan not found.');
            $this->redirect('/continuity/plans');
            return;
        }

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/continuity/plans/' . $id . '/edit');
            return;
        }

        $name = trim((string) ($_POST['plan_name'] ?? ''));
        $scope = trim((string) ($_POST['scope_description'] ?? ''));
        if ($name === '' || $scope === '') {
            Session::flash('error', 'A plan name and scope are required.');
            $this->redirect('/continuity/plans/' . $id . '/edit');
            return;
        }

        $this->plans->updatePlan($id, array_merge($this->collectFromRequest(), [
            'plan_name' => $name,
            'scope_description' => $scope,
            'updated_by' => Auth::user()['id'] ?? null,
        ]));

        Audit::log('Update', 'Continuity', "Updated continuity plan '$name'", ['plan_id' => $id]);
        Session::flash('success', 'Continuity plan updated.');
        $this->redirect('/continuity/plans');
    }

    /** A plan's RTO/RPO/steps age out of relevance as the app changes -- this is the "we actually re-checked this" signal, distinct from just editing it. */
    public function markReviewed(string $id): void
    {
        Auth::authorize('continuity.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/continuity/plans');
            return;
        }

        $this->plans->markReviewed($id, (int) (Auth::user()['id'] ?? 0));
        Audit::log('Review', 'Continuity', "Marked continuity plan #$id as reviewed", ['plan_id' => $id]);
        Session::flash('success', 'Marked as reviewed.');
        $this->redirect('/continuity/plans');
    }

    /** @return array<string, mixed> */
    private function collectFromRequest(): array
    {
        $rto = trim((string) ($_POST['rto_minutes'] ?? ''));
        $rpo = trim((string) ($_POST['rpo_minutes'] ?? ''));

        return [
            'rto_minutes' => $rto !== '' ? max(0, (int) $rto) : null,
            'rpo_minutes' => $rpo !== '' ? max(0, (int) $rpo) : null,
            'recovery_steps' => trim((string) ($_POST['recovery_steps'] ?? '')) ?: null,
            'key_contacts' => trim((string) ($_POST['key_contacts'] ?? '')) ?: null,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];
    }
}
