<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\HealthCheckResult;
use App\Services\HealthCheckService;

/** Automated Health Checks dashboard (Part 35-38). */
class HealthCheckController extends Controller
{
    private HealthCheckResult $results;

    public function __construct()
    {
        $this->results = new HealthCheckResult();
    }

    public function index(): void
    {
        Auth::authorize('health.view');
        $this->view('health/index', [
            'title' => 'System Health',
            'checks' => $this->results->latestByCheck(),
            'heartbeats' => $this->results->heartbeats(),
        ]);
    }

    /** Runs every check immediately -- the same probes bin/evaluate_health_checks.php runs on schedule, so an admin doesn't have to wait for the next sweep after fixing something. */
    public function runNow(): void
    {
        Auth::authorize('health.view');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/health');
            return;
        }

        $results = HealthCheckService::runAll();
        $unhealthy = count(array_filter($results, fn ($r) => $r['status'] === 'UNHEALTHY'));
        Audit::log('Run', 'Platform', 'Ran health checks on demand (' . count($results) . ' check(s), ' . $unhealthy . ' unhealthy)');

        Session::flash('success', 'Health checks completed. ' . ($unhealthy > 0 ? "$unhealthy unhealthy." : 'All clear.'));
        $this->redirect('/health');
    }
}
