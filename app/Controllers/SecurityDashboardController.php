<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\SecurityBlockedSource;
use App\Models\SecurityEventLog;
use App\Models\SecurityIncident;

class SecurityDashboardController extends Controller
{
    private SecurityEventLog $events;
    private SecurityIncident $incidents;
    private SecurityBlockedSource $blocks;

    public function __construct()
    {
        $this->events = new SecurityEventLog();
        $this->incidents = new SecurityIncident();
        $this->blocks = new SecurityBlockedSource();
    }

    public function index(): void
    {
        Auth::authorize('security.view');

        $this->view('security/overview/index', [
            'title' => 'Security Overview',
            'stats' => $this->buildStats(),
            'recentHighSeverity' => $this->events->recentHighSeverity(10),
            'trend' => $this->events->failedLoginsByHour(),
        ]);
    }

    /** Polled every 30-60s by the Overview page for a "live" feel -- a plain JSON fetch, not a websocket (see Phase 1 plan). */
    public function poll(): void
    {
        Auth::authorize('security.view');
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'stats' => $this->buildStats()]);
        exit;
    }

    private function buildStats(): array
    {
        $incidentCounts = $this->incidents->counts();
        $failedToday = $this->events->countByTypeToday('LOGIN_FAILED');

        return [
            'threat_level' => $this->threatLevel($incidentCounts),
            'events_today' => $this->events->countToday(),
            'failed_logins_today' => $failedToday,
            'open_incidents' => $incidentCounts['open'],
            'critical_incidents' => $incidentCounts['critical_open'],
            'active_blocks' => $this->blocks->countActive(),
        ];
    }

    /**
     * NORMAL / ELEVATED / HIGH / CRITICAL, derived from open-incident
     * severity mix -- not a separately-configurable setting in Phase 1,
     * a simple deterministic function of what's already on screen.
     */
    private function threatLevel(array $incidentCounts): string
    {
        if ($incidentCounts['critical_open'] > 0) {
            return 'CRITICAL';
        }
        if ($incidentCounts['high_open'] > 0) {
            return 'HIGH';
        }
        if ($incidentCounts['open'] > 0) {
            return 'ELEVATED';
        }
        return 'NORMAL';
    }
}
