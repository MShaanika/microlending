<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Services\DisbursementAccrualRestatementService;

/**
 * One-time, deliberately manual trigger for
 * DisbursementAccrualRestatementService -- brings every existing Active/
 * Current loan onto the new full-accrual disbursement accounting. Not a
 * recurring screen; safe to leave in place after use since the service is
 * idempotent (re-running skips loans already restated).
 */
class DisbursementAccrualRestatementController extends Controller
{
    public function preview(): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $this->view('accounting/disbursement_restatement/preview', [
            'title' => 'Disbursement Accrual Restatement',
            'summary' => DisbursementAccrualRestatementService::preview(),
        ]);
    }

    public function run(): void
    {
        Auth::authorize('accounting.adjustment_journals');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/disbursement-restatement');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $summary = DisbursementAccrualRestatementService::run($userId);

        Audit::log(
            'Update',
            'Accounting',
            'Ran disbursement accrual restatement: ' . $summary['loan_count'] . ' loan(s), '
                . 'interest ' . $summary['total_interest'] . ', levy ' . $summary['total_levy'] . ', stamp ' . $summary['total_stamp']
        );
        Session::flash('success', 'Restatement complete: ' . $summary['loan_count'] . ' loan(s) adjusted.');
        $this->redirect('/accounting/disbursement-restatement');
    }
}
