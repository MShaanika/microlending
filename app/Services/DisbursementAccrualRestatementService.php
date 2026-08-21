<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;

/**
 * One-time catch-up entry per loan, bringing loans disbursed under the old
 * method (interest recognized only on collection; NAMFISA levy/duty stamp
 * lumped into Loans Receivable) onto the new full-accrual method
 * (LoanController::postDisbursementAccounting() /
 * Payment::postCollectionAccounting()) without touching any historical
 * transaction.
 *
 * Rather than reversing/rewriting every past disbursement and payment
 * entry, this posts ONE adjusting journal per loan, for exactly the
 * portion still outstanding today:
 *
 *   Dr Interest Receivable       = interest_due - interest_paid
 *   Dr NAMFISA Levy Receivable   = namfisa_levy_due - namfisa_levy_paid
 *   Dr Stamp Duty Receivable     = duty_stamp_due - duty_stamp_paid
 *       Cr Loans Receivable          = (levy outstanding + stamp outstanding)
 *       Cr Deferred Interest Income  = interest outstanding
 *
 * This balances by construction and needs no knowledge of what happened
 * historically: whatever's already been collected was already recognized
 * as income under the old method (correctly, since both methods agree once
 * cash is in hand) -- only what's still owed needs to move out of the old
 * lumped 1020 balance and into its own receivable + deferred-income pair.
 * A loan with nothing outstanding (fully paid) needs no entry at all, so
 * Completed loans are naturally skipped without special-casing them.
 *
 * Written Off loans are deliberately EXCLUDED -- LoanWriteOffController
 * already posted its own bad-debt provisioning entry against the old
 * lumped 1020 balance, and this loan's remaining interest was never going
 * to be collected in the first place. Re-booking it as a fresh Interest
 * Receivable here would recreate a receivable that's already been
 * provisioned against, which is wrong in the other direction.
 *
 * Idempotent: re-running skips any loan that already has a RESTATEMENT
 * journal against it, so this can be safely re-run (e.g. after a new loan
 * is disbursed under the old code before this deploy went out) without
 * double-posting.
 */
class DisbursementAccrualRestatementService
{
    private const SOURCE_MODULE = 'DISBURSEMENT_ACCRUAL_RESTATEMENT';

    /**
     * Computes what run() would do without posting anything.
     * @return array{loan_count: int, total_interest: float, total_levy: float, total_stamp: float}
     */
    public static function preview(): array
    {
        return self::compute(false, null);
    }

    /**
     * @return array{loan_count: int, total_interest: float, total_levy: float, total_stamp: float, journal_ids: int[]}
     */
    public static function run(?int $userId): array
    {
        return self::compute(true, $userId);
    }

    private static function compute(bool $post, ?int $userId): array
    {
        $db = Database::connection();
        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();

        $loanReceivable = $accounts->idByCode('1020');
        $interestReceivable = $accounts->idByCode('1030');
        $levyReceivable = $accounts->idByCode('1051');
        $stampReceivable = $accounts->idByCode('1060');
        $deferredInterestIncome = $accounts->idByCode('2011');

        $rows = $db->query(
            "SELECT l.id, l.loan_no,
                    COALESCE(SUM(ls.interest_due - ls.interest_paid), 0) AS interest_outstanding,
                    COALESCE(SUM(ls.namfisa_levy_due - ls.namfisa_levy_paid), 0) AS levy_outstanding,
                    COALESCE(SUM(ls.duty_stamp_due - ls.duty_stamp_paid), 0) AS stamp_outstanding
             FROM loans l
             JOIN loan_schedules ls ON ls.loan_id = l.id
             WHERE l.loan_status IN ('Active', 'Current')
             GROUP BY l.id"
        )->fetchAll();

        $summary = ['loan_count' => 0, 'total_interest' => 0.0, 'total_levy' => 0.0, 'total_stamp' => 0.0, 'journal_ids' => []];

        foreach ($rows as $row) {
            $interest = round((float) $row['interest_outstanding'], 2);
            $levy = round((float) $row['levy_outstanding'], 2);
            $stamp = round((float) $row['stamp_outstanding'], 2);

            if ($interest <= 0.009 && $levy <= 0.009 && $stamp <= 0.009) {
                continue;
            }

            if ($post) {
                $already = $db->prepare(
                    "SELECT 1 FROM accounting_journal_entries
                     WHERE source_module = ? AND source_table = 'loans' AND source_id = ? LIMIT 1"
                );
                $already->execute([self::SOURCE_MODULE, $row['id']]);
                if ($already->fetchColumn()) {
                    continue;
                }
            }

            $summary['loan_count']++;
            $summary['total_interest'] += $interest;
            $summary['total_levy'] += $levy;
            $summary['total_stamp'] += $stamp;

            if (!$post) {
                continue;
            }

            $lines = [];
            if ($interest > 0.009) {
                $lines[] = ['account_id' => $interestReceivable, 'debit' => $interest, 'credit' => 0, 'description' => 'Restated interest receivable for ' . $row['loan_no']];
                $lines[] = ['account_id' => $deferredInterestIncome, 'debit' => 0, 'credit' => $interest, 'description' => 'Restated deferred interest income for ' . $row['loan_no']];
            }
            if ($levy > 0.009) {
                $lines[] = ['account_id' => $levyReceivable, 'debit' => $levy, 'credit' => 0, 'description' => 'Restated NAMFISA levy receivable for ' . $row['loan_no']];
            }
            if ($stamp > 0.009) {
                $lines[] = ['account_id' => $stampReceivable, 'debit' => $stamp, 'credit' => 0, 'description' => 'Restated stamp duty receivable for ' . $row['loan_no']];
            }
            $leviesAndStamp = round($levy + $stamp, 2);
            if ($leviesAndStamp > 0.009) {
                $lines[] = ['account_id' => $loanReceivable, 'debit' => 0, 'credit' => $leviesAndStamp, 'description' => 'Loans Receivable adjustment (levy/stamp split out) for ' . $row['loan_no']];
            }

            $journalId = $journal->post(
                self::SOURCE_MODULE,
                'loans',
                (int) $row['id'],
                $row['loan_no'],
                'Full-accrual disbursement restatement for ' . $row['loan_no'],
                $lines,
                $userId,
                date('Y-m-d'),
                'Adjustment'
            );
            $summary['journal_ids'][] = $journalId;
        }

        $summary['total_interest'] = round($summary['total_interest'], 2);
        $summary['total_levy'] = round($summary['total_levy'], 2);
        $summary['total_stamp'] = round($summary['total_stamp'], 2);

        return $summary;
    }
}
