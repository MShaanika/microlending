<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Services\InterestAccrualRestatementService;

/**
 * One-time, deliberately manual trigger for
 * InterestAccrualRestatementService -- brings every existing loan's
 * already-outstanding deferred interest/penalty income onto the new
 * accrual-basis recognition rule. Not a recurring screen; safe to leave in
 * place after use since the service is idempotent (re-running skips loans
 * already restated).
 */
class InterestAccrualRestatementController extends Controller
{
    public function preview(): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $this->view('accounting/interest_restatement/preview', [
            'title' => 'Interest & Penalty Accrual Restatement',
            'summary' => InterestAccrualRestatementService::preview(),
        ]);
    }

    public function run(): void
    {
        Auth::authorize('accounting.adjustment_journals');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/interest-restatement');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $summary = InterestAccrualRestatementService::run($userId);

        Audit::log(
            'Update',
            'Accounting',
            'Ran interest/penalty accrual restatement: ' . $summary['loan_count'] . ' loan(s) for interest ('
                . 'recognized ' . $summary['total_recognized'] . ', reversed ' . $summary['total_reversed'] . '), '
                . $summary['penalty_loan_count'] . ' loan(s) for penalty (recognized ' . $summary['total_penalty_recognized'] . ')'
        );
        Session::flash('success', 'Restatement complete: ' . $summary['loan_count'] . ' loan(s) adjusted for interest, ' . $summary['penalty_loan_count'] . ' for penalty.');
        $this->redirect('/accounting/interest-restatement');
    }
}
