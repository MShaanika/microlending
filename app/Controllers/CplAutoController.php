<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Services\CplAutoSubmissionService;

/**
 * The switch and the confirmations for the automatic CPL submission (see CplAutoSubmissionService). Viewing the panel needs the normal CPL
 * permission (it is on the CPL dashboard); switching it on or off, and answering "does Creditinfo have this file?", needs the approval permission:
 * these decisions send (or stop sending) files to a credit bureau, so they are not for everybody who can look at the reports.
 */
class CplAutoController extends Controller
{
    private const PERMISSION = 'reports.cpl_approve';

    public function enableForm(): void
    {
        Auth::authorize(self::PERMISSION);
        $service = new CplAutoSubmissionService();
        $since = trim((string) ($_GET['since'] ?? ''));
        $this->view('reports/cpl_export/auto/enable', [
            'title' => 'Turn on automatic CPL submission',
            'panel' => $service->panel(),
            'preview' => $service->firstFilePreview(CplAutoSubmissionService::isRealDate($since) ? $since : null),
            'since' => $since,
            'blockers' => $service->enableBlockers(Auth::user()['id'] ?? null),
            'recipients' => $service->recipientList(),
            'cron' => $service->cronHealth(),
        ]);
    }

    public function enable(): void
    {
        Auth::authorize(self::PERMISSION);
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/auto/enable');
            return;
        }
        $since = trim((string) ($_POST['since'] ?? ''));
        // The preview recorded in the standing approval (the first live file may not exceed it by more than 20%) is ALWAYS measured here on the server.
        // The numbers the page posted are only used to notice a stale page: if they differ by more than 10%, nothing is enabled and the page is shown again.
        $service = new CplAutoSubmissionService();
        $postedPreview = isset($_POST['preview_daily'], $_POST['preview_monthly']) && ctype_digit((string) $_POST['preview_daily']) && ctype_digit((string) $_POST['preview_monthly'])
            ? ['daily' => (int) $_POST['preview_daily'], 'monthly' => (int) $_POST['preview_monthly']]
            : null;
        $check = $service->previewForEnable($since, $postedPreview);
        if ($check['stale']) {
            Session::flash('error', 'The numbers changed while you were looking at the page (now ' . $check['preview']['daily'] . ' daily and ' . $check['preview']['monthly'] . ' monthly record(s)). Nothing was turned on. Please read the fresh preview below and confirm again.');
            $this->redirect('/reports/cpl-export/auto/enable' . (CplAutoSubmissionService::isRealDate($since) ? '?since=' . urlencode($since) : ''));
            return;
        }
        $preview = $check['preview'];
        try {
            $service->setEnabled(
                true,
                Auth::user()['id'] ?? null,
                !empty($_POST['acknowledge_completeness']),
                $since,
                !empty($_POST['acknowledge_standing_approval']),
                $preview,
                !empty($_POST['acknowledge_no_heartbeat'])
            );
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/reports/cpl-export/auto/enable');
            return;
        }
        Audit::log('Update', 'CPL', 'Turned ON automatic CPL submission (daily and monthly files to Creditinfo) and gave the STANDING APPROVAL for it (it replaces the approval of each batch), acknowledging that the Loan Questions do not cover the full historic loan book' . ($since !== '' ? '; daily registrations only for loans paid out on or after ' . $since : '') . ($preview !== null ? '; previewed ' . $preview['daily'] . ' daily and ' . $preview['monthly'] . ' monthly record(s)' : ''));
        Session::flash('success', 'Automatic CPL submission is now ON. The next scheduled run will send what is due.');
        $this->redirect('/reports/cpl-export/batches');
    }

    public function disable(): void
    {
        Auth::authorize(self::PERMISSION);
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/batches');
            return;
        }
        (new CplAutoSubmissionService())->setEnabled(false, Auth::user()['id'] ?? null);
        Audit::log('Update', 'CPL', 'Turned OFF automatic CPL submission (the standing approval is revoked)');
        Session::flash('success', 'Automatic CPL submission is now OFF and the standing approval is revoked. Files are still prepared as drafts, but nothing is sent.');
        $this->redirect('/reports/cpl-export/batches');
    }

    /** A person says the SFTP settings were fixed after Creditinfo's server refused the login: lifts the hard stop (the switch stays OFF). */
    public function acknowledgeHalt(): void
    {
        Auth::authorize(self::PERMISSION);
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/batches');
            return;
        }
        if ((new CplAutoSubmissionService())->acknowledgeHalt(Auth::user()['id'] ?? null)) {
            Audit::log('Update', 'CPL', 'Acknowledged the automatic CPL submission stop after the SFTP login was refused');
            Session::flash('success', 'Acknowledged. Automatic submission stays OFF; check the SFTP settings, then turn it on again when you are ready.');
        } else {
            Session::flash('error', 'There is no stop to acknowledge.');
        }
        $this->redirect('/reports/cpl-export/batches');
    }

    /** "Creditinfo has this file" / "Creditinfo does NOT have this file" for an upload whose outcome was unknown. */
    public function resolve(string $id): void
    {
        Auth::authorize(self::PERMISSION);
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/reports/cpl-export/batches');
            return;
        }
        $answer = (string) ($_POST['answer'] ?? '');
        if (!in_array($answer, ['has', 'hasnot'], true)) {
            Session::flash('error', 'Please choose one of the two answers.');
            $this->redirect('/reports/cpl-export/batches');
            return;
        }
        try {
            (new CplAutoSubmissionService())->resolveUncertain($id, $answer === 'has', Auth::user()['id'] ?? null);
        } catch (\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/reports/cpl-export/batches');
            return;
        }
        Audit::log('Update', 'CPL', 'Confirmed that Creditinfo ' . ($answer === 'has' ? 'HAS' : 'does NOT have') . ' the automatically uploaded file of CPL batch #' . $id);
        Session::flash('success', $answer === 'has' ? 'Recorded: Creditinfo has the file. Its loans are marked as reported.' : 'Recorded: Creditinfo does not have the file. Its loans will be in the next automatic file.');
        $this->redirect('/reports/cpl-export/batches');
    }
}
