<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\IntakeSource;

/**
 * Read-only view of external website intake sources (e.g. the client's own
 * "Apply Now" form) so staff can retrieve the source code + API token
 * needed to wire that external form up, without the token ever needing to
 * be pasted into chat/tickets/etc.
 */
class IntakeSourceController extends Controller
{
    private IntakeSource $sources;

    public function __construct()
    {
        $this->sources = new IntakeSource();
    }

    public function index(): void
    {
        Auth::authorize('admin.system_settings');
        $this->view('settings/intake_sources/index', [
            'title' => 'Intake Sources',
            'sources' => $this->sources->allSources(),
        ]);
    }
}
