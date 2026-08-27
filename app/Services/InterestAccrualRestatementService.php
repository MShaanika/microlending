<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\InterestAccrual;

/**
 * One-time catch-up for loans that already existed when interest/penalty
 * income moved from cash-basis to accrual-basis recognition (see
 * InterestAccrualService and the PenaltyAccrualService change alongside it).
 * Before this change, EVERY loan had its whole-term interest booked as a
 * receivable at disbursement (LoanController::postDisbursementAccounting(),
 * pre-change) with the income side sitting in Deferred Interest Income
 * (2011) until collected -- this posts the one-time journal that recognizes
 * whatever portion of that deferred balance is properly earned by now, and
 * reverses the rest back out so 1030/2011 end up holding exactly what the
 * new accrual-basis rule would have booked, with nothing missing and
 * nothing double-counted once the ordinary going-forward accrual run
 * reaches those same installments later.
 *
 * This codebase's loan history is mixed: some loans predate the lump-sum-
 * at-disbursement method entirely (never booked ANY 1030/2011 -- their
 * interest was pure cash-basis, recognized only on collection under an
 * even older code path), some were disbursed under the lump-sum method and
 * genuinely carry a 2011 balance, and loans disbursed under the new
 * accrual-basis code (this change) never touch 2011 at all. Computing
 * "outstanding interest" purely from loan_schedules and assuming it
 * belongs in 2011 would wrongly manufacture a 2011/1030 entry for a loan
 * that never had one -- so every loan is first gated on its ACTUAL posted
 * 2011 balance (summed from real journal lines, source-of-truth), and only
 * loans with a genuine positive balance are restated at all. A loan with
 * no 2011 balance needs no restatement -- its outstanding schedule
 * interest was never a receivable in the first place, and is picked up
 * exactly like a brand-new loan's would be, by the ordinary
 * InterestAccrualService::accrue() as each installment's due date arrives.
 *
 * For each loan that IS restated, its loan_schedules rows split two ways
 * as of the restatement date to determine how the 2011 balance is spent:
 *   - due_date <= restatement date, OR interest_paid > 0 (collected early
 *     under the old rule): fully earned now -- "recognize now".
 *   - due_date > restatement date AND interest_paid = 0: not yet earned --
 *     "reverse, not yet earned", moved back OUT of 1030/2011 so the
 *     ordinary accrual run books it fresh when its real due date arrives.
 * The loan's ACTUAL 2011 balance (not the schedule-computed total) is what
 * gets debited, guaranteeing full retirement to zero even if the schedule
 * and GL have drifted apart historically (e.g. an old reschedule that
 * forgave interest without an accounting entry, before this change) --
 * "reverse, not yet earned" absorbs any such gap first, floored at zero,
 * with "recognize now" capped at the actual balance as a last resort.
 *
 * One balanced journal per loan:
 *   Dr Deferred Interest Income (2011)  = actual 2011 balance for this loan
 *       Cr Interest Income (4010)           = recognize_now
 *       Cr Interest Receivable (1030)       = reverse_unearned
 *
 * interest_accruals is backfilled for every "recognize now" row (so the
 * ordinary accrual run skips them, via its own NOT EXISTS guard) but NOT
 * for "reverse, not yet earned" rows, which must stay accruable.
 *
 * Separately (own journal, own idempotency guard, no earned/unearned split
 * needed -- a charged penalty is always fully earned the moment it's
 * charged): every loan with outstanding 'Charged' penalties gets
 *   Dr Deferred Penalty Income (2050) / Cr Penalty Income (4020)
 * for the full outstanding amount.
 *
 * Idempotent per loan per journal type via a source_module existence
 * guard, same pattern as DisbursementAccrualRestatementService. Written
 * Off loans are excluded -- their remaining interest/penalty was never
 * going to be collected; LoanWriteOffController handles that loan's own
 * books directly.
 */
class InterestAccrualRestatementService
{
    private const INTEREST_SOURCE_MODULE = 'INTEREST_ACCRUAL_RESTATEMENT';
    private const PENALTY_SOURCE_MODULE = 'PENALTY_ACCRUAL_RESTATEMENT';

    /** @return array{loan_count: int, total_recognized: float, total_reversed: float, penalty_loan_count: int, total_penalty_recognized: float} */
    public static function preview(): array
    {
        return self::compute(false, null);
    }

    /** @return array{loan_count: int, total_recognized: float, total_reversed: float, penalty_loan_count: int, total_penalty_recognized: float, journal_ids: int[]} */
    public static function run(?int $userId): array
    {
        return self::compute(true, $userId);
    }

    private static function compute(bool $post, ?int $userId): array
    {
        $db = Database::connection();
        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();
        $accruals = new InterestAccrual();
        $restatementDate = date('Y-m-d');

        $summary = [
            'loan_count' => 0, 'total_recognized' => 0.0, 'total_reversed' => 0.0,
            'penalty_loan_count' => 0, 'total_penalty_recognized' => 0.0,
            'journal_ids' => [],
        ];

        // --- Interest ---
        // Ground truth for "does this loan actually have deferred interest
        // income to retire": the real posted 2011 balance, not an assumption
        // from loan_schedules -- see class docblock for why this matters.
        // 2011 is only ever touched via a 'loans'-sourced journal (the old
        // lump-sum disbursement entry, or DisbursementAccrualRestatementService)
        // or a 'payments'-sourced journal (the old collection reclassification,
        // via that payment's own loan_id).
        $balances = $db->query(
            "SELECT loan_id, SUM(net) AS balance FROM (
                 SELECT je.source_id AS loan_id, SUM(jl.credit - jl.debit) AS net
                 FROM accounting_journal_lines jl
                 JOIN accounting_journal_entries je ON je.id = jl.journal_id
                 JOIN accounting_accounts aa ON aa.id = jl.account_id
                 WHERE aa.account_code = '2011' AND je.status = 'Posted' AND je.source_table = 'loans'
                 GROUP BY je.source_id
                 UNION ALL
                 SELECT p.loan_id, SUM(jl.credit - jl.debit) AS net
                 FROM accounting_journal_lines jl
                 JOIN accounting_journal_entries je ON je.id = jl.journal_id
                 JOIN accounting_accounts aa ON aa.id = jl.account_id
                 JOIN payments p ON p.id = je.source_id AND je.source_table = 'payments'
                 WHERE aa.account_code = '2011' AND je.status = 'Posted'
                 GROUP BY p.loan_id
             ) x
             GROUP BY loan_id
             HAVING SUM(net) > 0.009"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $rows = $db->query(
            "SELECT ls.id AS schedule_id, ls.loan_id, ls.due_date, ls.interest_due, ls.interest_paid,
                    l.loan_no, l.borrower_id
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             WHERE l.loan_status IN ('Active', 'Current')
               AND ls.interest_due > 0
               AND NOT EXISTS (SELECT 1 FROM interest_accruals ia WHERE ia.schedule_id = ls.id)
             ORDER BY ls.loan_id, ls.due_date"
        )->fetchAll();

        $byLoan = [];
        foreach ($rows as $row) {
            // Only a loan with a genuine 2011 balance needs restating -- see
            // class docblock. Everything else is left for the ordinary
            // going-forward accrual run to pick up naturally.
            if (isset($balances[$row['loan_id']])) {
                $byLoan[$row['loan_id']][] = $row;
            }
        }

        foreach ($byLoan as $loanId => $loanRows) {
            $recognizeNowRows = [];
            $recognizeNow = 0.0;
            $reverseUnearned = 0.0;

            foreach ($loanRows as $row) {
                $due = round((float) $row['interest_due'], 2);
                $paid = round((float) $row['interest_paid'], 2);
                $outstanding = round($due - $paid, 2);

                if ($row['due_date'] <= $restatementDate || $paid > 0) {
                    if ($outstanding > 0.009) {
                        $recognizeNow += $outstanding;
                        $recognizeNowRows[] = $row + ['outstanding' => $outstanding];
                    }
                } elseif ($due > 0.009) {
                    $reverseUnearned += $due;
                }
            }

            $recognizeNow = round($recognizeNow, 2);
            $reverseUnearned = round($reverseUnearned, 2);

            // The loan's ACTUAL 2011 balance is authoritative -- not the
            // schedule-computed total, which can drift from it (e.g. an old
            // reschedule that forgave interest with no accounting entry,
            // before this change existed). Reconcile the split to that
            // actual balance so 2011 always fully retires to zero: absorb
            // any gap into "reverse, not yet earned" first, floored at
            // zero, then cap "recognize now" as a last resort.
            $interestOutstanding = round((float) $balances[$loanId], 2);
            $reverseUnearned = max(0.0, round($interestOutstanding - $recognizeNow, 2));
            $capped = round($recognizeNow + $reverseUnearned, 2) > $interestOutstanding;
            if ($capped) {
                // Rare: the schedule's "recognize now" total exceeds the
                // loan's actual 2011 balance (GL/schedule drift). Cap to
                // what's actually there and skip the per-row backfill below
                // -- crediting some but not all of these rows' full amounts
                // to interest_accruals would misrepresent which rows were
                // actually recognized.
                $recognizeNow = $interestOutstanding;
                $reverseUnearned = 0.0;
            }

            if ($interestOutstanding <= 0.009) {
                continue;
            }

            if ($post) {
                $already = $db->prepare(
                    "SELECT 1 FROM accounting_journal_entries WHERE source_module = ? AND source_table = 'loans' AND source_id = ? LIMIT 1"
                );
                $already->execute([self::INTEREST_SOURCE_MODULE, $loanId]);
                if ($already->fetchColumn()) {
                    continue;
                }
            }

            $summary['loan_count']++;
            $summary['total_recognized'] += $recognizeNow;
            $summary['total_reversed'] += $reverseUnearned;

            if (!$post) {
                continue;
            }

            $loanNo = $loanRows[0]['loan_no'];
            $lines = [
                ['account_id' => $accounts->idByCode('2011'), 'debit' => $interestOutstanding, 'credit' => 0, 'description' => 'Deferred interest income retired for ' . $loanNo],
            ];
            if ($recognizeNow > 0.009) {
                $lines[] = ['account_id' => $accounts->idByCode('4010'), 'debit' => 0, 'credit' => $recognizeNow, 'description' => 'Interest income recognized on restatement for ' . $loanNo];
            }
            if ($reverseUnearned > 0.009) {
                $lines[] = ['account_id' => $accounts->idByCode('1030'), 'debit' => 0, 'credit' => $reverseUnearned, 'description' => 'Not-yet-earned interest reversed on restatement for ' . $loanNo];
            }

            $journalId = $journal->post(
                self::INTEREST_SOURCE_MODULE,
                'loans',
                (int) $loanId,
                $loanNo,
                'Accrual-basis interest restatement for ' . $loanNo,
                $lines,
                $userId,
                $restatementDate,
                'Adjustment'
            );
            $summary['journal_ids'][] = $journalId;

            foreach ($capped ? [] : $recognizeNowRows as $row) {
                $accruals->create([
                    'loan_id' => $row['loan_id'],
                    'borrower_id' => $row['borrower_id'],
                    'schedule_id' => $row['schedule_id'],
                    'accrual_no' => generate_reference('IAC'),
                    'accrual_date' => $restatementDate,
                    'amount' => $row['outstanding'],
                    'status' => 'Accrued',
                    'accrued_by' => $userId,
                ]);
            }
        }

        $summary['total_recognized'] = round($summary['total_recognized'], 2);
        $summary['total_reversed'] = round($summary['total_reversed'], 2);

        // --- Penalty ---
        $penaltyRows = $db->query(
            "SELECT ls.loan_id, l.loan_no, COALESCE(SUM(ls.penalty_due - ls.penalty_paid), 0) AS penalty_outstanding
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             WHERE l.loan_status IN ('Active', 'Current')
             GROUP BY ls.loan_id
             HAVING penalty_outstanding > 0.009"
        )->fetchAll();

        foreach ($penaltyRows as $row) {
            $loanId = (int) $row['loan_id'];
            $penaltyOutstanding = round((float) $row['penalty_outstanding'], 2);

            if ($post) {
                $already = $db->prepare(
                    "SELECT 1 FROM accounting_journal_entries WHERE source_module = ? AND source_table = 'loans' AND source_id = ? LIMIT 1"
                );
                $already->execute([self::PENALTY_SOURCE_MODULE, $loanId]);
                if ($already->fetchColumn()) {
                    continue;
                }
            }

            $summary['penalty_loan_count']++;
            $summary['total_penalty_recognized'] += $penaltyOutstanding;

            if (!$post) {
                continue;
            }

            $journalId = $journal->post(
                self::PENALTY_SOURCE_MODULE,
                'loans',
                $loanId,
                $row['loan_no'],
                'Accrual-basis penalty restatement for ' . $row['loan_no'],
                [
                    ['account_id' => $accounts->idByCode('2050'), 'debit' => $penaltyOutstanding, 'credit' => 0, 'description' => 'Deferred penalty income retired for ' . $row['loan_no']],
                    ['account_id' => $accounts->idByCode('4020'), 'debit' => 0, 'credit' => $penaltyOutstanding, 'description' => 'Penalty income recognized on restatement for ' . $row['loan_no']],
                ],
                $userId,
                $restatementDate,
                'Adjustment'
            );
            $summary['journal_ids'][] = $journalId;
        }

        $summary['total_penalty_recognized'] = round($summary['total_penalty_recognized'], 2);

        return $summary;
    }
}
