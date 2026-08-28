<?php

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Security;
use App\Core\Session;
use App\Services\LoanStatusBackfillService;

/**
 * One-time, deliberately manual trigger for LoanStatusBackfillService --
 * brings every existing loan's payment_status/aging_bucket/
 * collection_status/credit_status columns up to date with its real
 * historical arrears state, and normalizes any remaining loan_status
 * = 'Current' rows to 'Active'. Not a recurring screen; safe to leave in
 * place after use since the service is idempotent (re-running only touches
 * loans that have genuinely drifted since the last run).
 */
class LoanStatusBackfillController extends Controller
{
    public function preview(): void
    {
        Auth::authorize('accounting.adjustment_journals');
        $this->view('accounting/loan_status_backfill/preview', [
            'title' => 'Loan Status Dimensions Backfill',
            'summary' => LoanStatusBackfillService::preview(),
        ]);
    }

    public function run(): void
    {
        Auth::authorize('accounting.adjustment_journals');

        if (!Security::verifyCsrf($_POST['_csrf'] ?? null)) {
            Session::flash('error', 'Security token expired. Please try again.');
            $this->redirect('/accounting/loan-status-backfill');
            return;
        }

        $userId = Auth::user()['id'] ?? null;
        $summary = LoanStatusBackfillService::run($userId);

        Audit::log(
            'Update',
            'Accounting',
            'Ran loan status dimensions backfill: ' . $summary['changed_count'] . ' of ' . $summary['loan_count']
                . ' loan(s) updated, ' . $summary['current_status_count'] . ' loan_status=Current row(s) normalized to Active'
        );
        Session::flash('success', 'Backfill complete: ' . $summary['changed_count'] . ' loan(s) updated.');
        $this->redirect('/accounting/loan-status-backfill');
    }
}
