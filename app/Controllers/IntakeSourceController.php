<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\IntakeSource;

/**
 * Read-only view of external website intake sources (e.g. the client's own
 * "Apply Now" form) so staff can retrieve the source code + API token
 * needed to wire that external form up, without the token ever needing to
 * be pasted into chat/tickets/etc. Also supports rotating a token if it's
 * ever exposed (pasted somewhere it shouldn't have been, leaked, etc.) --
 * regenerating immediately invalidates the old one, so the external form
 * must be updated with the new value straight after.
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

    public function regenerateToken(string $id): void
    {
        Auth::authorize('admin.system_settings');
        $id = (int) $id;

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/settings/intake-sources');
            return;
        }

        $newToken = bin2hex(random_bytes(20));
        $this->sources->updateToken($id, $newToken);

        Audit::log('Update', 'Intake Sources', 'Regenerated API token for intake source #' . $id . ' -- the old token is now invalid.');
        Session::flash('success', 'Token regenerated. Update the external form with the new token now -- the old one no longer works.');
        $this->redirect('/settings/intake-sources');
    }
}
