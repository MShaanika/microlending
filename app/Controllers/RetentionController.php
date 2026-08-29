<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\RetentionPolicy;
use App\Services\LegalHoldService;
use App\Services\RetentionService;

/** Retention & Legal Hold administration (Part 44-48). */
class RetentionController extends Controller
{
    private RetentionPolicy $policies;

    public function __construct()
    {
        $this->policies = new RetentionPolicy();
    }

    public function index(): void
    {
        Auth::authorize('retention.view');
        $policies = $this->policies->allPolicies();
        // Preview counts (dry-run, no deletion) shown live on the list --
        // an admin should see "12 eligible, 1 held" without clicking in.
        foreach ($policies as &$p) {
            $p['preview'] = RetentionService::preview($p);
        }
        unset($p);

        $this->view('retention/index', ['title' => 'Retention Policies', 'policies' => $policies]);
    }

    public function show(string $id): void
    {
        Auth::authorize('retention.view');
        $policy = $this->policies->find((int) $id);
        if (!$policy) {
            Session::flash('error', 'Policy not found.');
            $this->redirect('/retention');
            return;
        }

        $this->view('retention/show', [
            'title' => $policy['policy_name'],
            'policy' => $policy,
            'preview' => RetentionService::preview($policy),
            'runs' => $this->policies->recentRuns((int) $id),
        ]);
    }

    public function update(string $id): void
    {
        Auth::authorize('retention.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/retention/' . $id);
            return;
        }

        $policy = $this->policies->find($id);
        if (!$policy) {
            Session::flash('error', 'Policy not found.');
            $this->redirect('/retention');
            return;
        }

        $retentionDays = max(0, (int) ($_POST['retention_days'] ?? $policy['retention_days']));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        $this->policies->updatePolicy($id, [
            'retention_days' => $retentionDays,
            'is_active' => $isActive,
            'updated_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'Continuity', "Updated retention policy {$policy['policy_key']} (retention_days {$policy['retention_days']}->$retentionDays, active {$policy['is_active']}->$isActive)", ['policy_id' => $id]);
        Session::flash('success', 'Policy updated.');
        $this->redirect('/retention/' . $id);
    }

    /** Real deletion -- requires an explicit confirmation checkbox in the form (Part 48: never uncontrolled mass deletion). */
    public function execute(string $id): void
    {
        Auth::authorize('retention.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/retention/' . $id);
            return;
        }
        if (empty($_POST['confirm'])) {
            Session::flash('error', 'You must explicitly confirm before a retention policy deletes anything.');
            $this->redirect('/retention/' . $id);
            return;
        }

        $policy = $this->policies->find($id);
        if (!$policy || !$policy['is_active']) {
            Session::flash('error', 'Policy not found or inactive.');
            $this->redirect('/retention');
            return;
        }

        $result = RetentionService::execute($policy, (int) (Auth::user()['id'] ?? 0));
        Session::flash('success', sprintf('Deleted %d row(s) (%d held by legal hold, skipped).', $result['deleted'], $result['held']));
        $this->redirect('/retention/' . $id);
    }

    // -- Legal holds --

    public function holds(): void
    {
        Auth::authorize('retention.view');
        $this->view('retention/holds', ['title' => 'Legal Holds', 'holds' => $this->policies->allHolds()]);
    }

    public function placeHold(): void
    {
        Auth::authorize('retention.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/retention/holds');
            return;
        }

        $table = trim((string) ($_POST['resource_table'] ?? ''));
        $resourceId = (int) ($_POST['resource_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($table === '' || !$resourceId || $reason === '') {
            Session::flash('error', 'Table, record ID, and reason are all required to place a hold.');
            $this->redirect('/retention/holds');
            return;
        }

        LegalHoldService::place($table, $resourceId, $reason, (int) (Auth::user()['id'] ?? 0));
        Session::flash('success', 'Legal hold placed.');
        $this->redirect('/retention/holds');
    }

    public function releaseHold(string $id): void
    {
        Auth::authorize('retention.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/retention/holds');
            return;
        }
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'Released by administrator';
        LegalHoldService::release($id, (int) (Auth::user()['id'] ?? 0), $reason);
        Session::flash('success', 'Hold released.');
        $this->redirect('/retention/holds');
    }
}
