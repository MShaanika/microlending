<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\SlaInstance;

/** Policy administration -- Part 16's "these are examples only, do not hard-code them. Build configuration." An admin creates real policies with real durations here; no durations are seeded by migration. */
class SlaPolicyController extends Controller
{
    private SlaInstance $model;

    public function __construct()
    {
        $this->model = new SlaInstance();
    }

    public function index(): void
    {
        Auth::authorize('sla.view');
        $this->view('sla/policies/index', [
            'title' => 'SLA Policies',
            'policies' => $this->model->allPolicies(),
        ]);
    }

    public function create(): void
    {
        Auth::authorize('sla.manage');
        $this->view('sla/policies/create', ['title' => 'Create SLA Policy', 'errors' => []]);
    }

    public function store(): void
    {
        Auth::authorize('sla.manage');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/sla/policies/create');
            return;
        }

        $policyKey = trim((string) ($_POST['policy_key'] ?? ''));
        $duration = (int) ($_POST['duration_minutes'] ?? 0);
        $errors = [];
        if ($policyKey === '' || !preg_match('/^[a-z0-9_]+$/', $policyKey)) {
            $errors[] = 'Policy key must be lowercase letters, numbers, and underscores only.';
        }
        if ($duration <= 0) {
            $errors[] = 'Duration must be a positive number of minutes.';
        }

        if ($errors) {
            $this->view('sla/policies/create', ['title' => 'Create SLA Policy', 'errors' => $errors]);
            return;
        }

        $id = $this->model->createPolicy([
            'policy_key' => $policyKey,
            'policy_name' => trim((string) ($_POST['policy_name'] ?? $policyKey)),
            'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            'module' => trim((string) ($_POST['module'] ?? 'Operations')),
            'resource_type' => trim((string) ($_POST['resource_type'] ?? '')),
            'duration_minutes' => $duration,
            'business_hours_aware' => !empty($_POST['business_hours_aware']) ? 1 : 0,
            'at_risk_threshold_percent' => max(1, min(99, (int) ($_POST['at_risk_threshold_percent'] ?? 75))),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'created_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Create', 'Operations', 'Created SLA policy ' . $policyKey, ['policy_id' => $id]);
        Session::flash('success', 'SLA policy created.');
        $this->redirect('/sla/policies');
    }

    /** Edit-only for existing policies (thresholds/duration/active), same pattern as the Security Rules page -- no separate edit view, the index page's inline form posts here. */
    public function update(string $id): void
    {
        Auth::authorize('sla.manage');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/sla/policies');
            return;
        }

        $policy = $this->model->findPolicy($id);
        if (!$policy) {
            Session::flash('error', 'Policy not found.');
            $this->redirect('/sla/policies');
            return;
        }

        $duration = max(1, (int) ($_POST['duration_minutes'] ?? $policy['duration_minutes']));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        $this->model->updatePolicy($id, [
            'duration_minutes' => $duration,
            'business_hours_aware' => !empty($_POST['business_hours_aware']) ? 1 : 0,
            'at_risk_threshold_percent' => max(1, min(99, (int) ($_POST['at_risk_threshold_percent'] ?? $policy['at_risk_threshold_percent']))),
            'is_active' => $isActive,
            'updated_by' => Auth::user()['id'] ?? null,
        ]);

        Audit::log('Update', 'Operations', 'Updated SLA policy ' . $policy['policy_key'] . " (active {$policy['is_active']}->$isActive)", ['policy_id' => $id]);
        Session::flash('success', 'Policy updated.');
        $this->redirect('/sla/policies');
    }
}
