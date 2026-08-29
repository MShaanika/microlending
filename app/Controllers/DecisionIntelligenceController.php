<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\DecisionIntelligenceService;

/** Decision Intelligence dashboard (Part 7-9) -- read-only insight over what every other framework already captured. View-only permission, nothing here writes. */
class DecisionIntelligenceController extends Controller
{
    public function index(): void
    {
        Auth::authorize('intelligence.view');

        $this->view('intelligence/index', [
            'title' => 'Decision Intelligence',
            'hotspots' => DecisionIntelligenceService::hotspotsByModule(30),
            'recurringPatterns' => DecisionIntelligenceService::recurringPatterns(90, 3),
            'trend' => DecisionIntelligenceService::exceptionTrend(30),
            'resolutionMetrics' => DecisionIntelligenceService::resolutionMetrics(90),
            'recentRootCauses' => DecisionIntelligenceService::recentRootCauses(10),
        ]);
    }
}
