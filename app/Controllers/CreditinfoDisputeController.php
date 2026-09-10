<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Models\Borrower;
use App\Models\CreditinfoDispute;

/**
 * Minimal borrower-level Creditinfo bureau dispute register -- item 6's
 * hard listing block needs somewhere to record that a dispute exists,
 * since nothing else in DesertLedger tracked this before Public Defaults
 * needed it. Deliberately small: open/resolve only, no workflow of its own.
 */
class CreditinfoDisputeController extends Controller
{
    private CreditinfoDispute $disputes;
    private Borrower $borrowers;

    public function __construct()
    {
        $this->disputes = new CreditinfoDispute();
        $this->borrowers = new Borrower();
    }

    public function index(): void
    {
        Auth::authorize('creditinfo.disputes.manage');
        $this->view('creditinfo/disputes/index', [
            'title' => 'Creditinfo Disputes',
            'disputes' => $this->disputes->allDisputes(),
        ]);
    }

    public function store(): void
    {
        Auth::authorize('creditinfo.disputes.manage');
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/disputes');
            return;
        }

        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $borrower = $this->borrowers->find($borrowerId);
        if (!$borrower) {
            Session::flash('error', 'Borrower not found.');
            $this->redirect('/creditinfo/disputes');
            return;
        }

        if ($this->disputes->hasOpenDispute($borrowerId)) {
            Session::flash('error', 'This borrower already has an open dispute on record.');
            $this->redirect('/creditinfo/disputes');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $id = $this->disputes->create([
            'borrower_id' => $borrowerId,
            'reference' => trim($_POST['reference'] ?? '') ?: null,
            'status' => 'Open',
            'opened_at' => date('Y-m-d H:i:s'),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'recorded_by' => $userId,
        ]);

        Audit::log('Create', 'Creditinfo', 'Opened Creditinfo dispute #' . $id . ' for borrower #' . $borrowerId);
        Session::flash('success', 'Dispute recorded. New Public Default listings for this borrower are now blocked until it is resolved.');
        $this->redirect('/creditinfo/disputes');
    }

    public function resolve(string $id): void
    {
        Auth::authorize('creditinfo.disputes.manage');
        $id = (int) $id;
        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/creditinfo/disputes');
            return;
        }

        $dispute = $this->disputes->find($id);
        if (!$dispute || $dispute['status'] !== 'Open') {
            Session::flash('error', 'Only an open dispute can be resolved.');
            $this->redirect('/creditinfo/disputes');
            return;
        }

        $userId = (int) (Auth::user()['id'] ?? 0);
        $this->disputes->resolve($id, $userId);

        Audit::log('Update', 'Creditinfo', 'Resolved Creditinfo dispute #' . $id);
        Session::flash('success', 'Dispute resolved.');
        $this->redirect('/creditinfo/disputes');
    }
}
