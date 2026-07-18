<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Penalty;

/**
 * Identifies overdue installments whose shortfall hasn't yet been rolled
 * into a penalty, and computes that penalty: 5% (the loan's penalty_rate)
 * of specifically the amount left unpaid on that installment -- not the
 * installment's full original amount. The penalty is charged onto the
 * *next* installment's total (raising its penalty_due/total_due), not the
 * overdue installment itself, which keeps its original total unchanged
 * forever and only shows what was actually paid against it. If there is
 * no next installment (the loan's last one was missed), the penalty lands
 * on that same row instead, since there's nowhere else to roll it.
 *
 * Two further caps apply per loan, regardless of how many installments
 * are overdue:
 *  - At most one penalty per calendar month (a loan already penalized in
 *    the current month doesn't get a second one even if a different
 *    installment falls overdue in the same month).
 *  - At most MAX_PENALTIES_PER_LOAN penalties over the loan's lifetime --
 *    once reached, no further penalty is ever charged on that loan no
 *    matter how much is later paid or how overdue it becomes.
 * Both are lifetime/monthly properties of the loan, not the installment,
 * so they're enforced in PHP after fetching candidates (a single accrue()
 * run can see several installments newly overdue for the same loan at
 * once, before any of them has a penalties row yet to check against).
 *
 * loan_schedules.opening_balance/closing_balance are deliberately left
 * untouched here -- they track each row's principal-only amortization
 * balance (set once at generation, already correctly displayed in the
 * loan's own schedule table) and are a different concept from "total
 * still owed including any rolled-forward penalty". Rolling a penalty
 * forward only changes the target row's penalty_due/total_due.
 *
 * This is the accrual half of the deferred-income pattern: charging a
 * penalty here only raises a Penalty Receivable against Deferred Penalty
 * Income. It is not recognized as P&L income until actually collected
 * (Payment::postCollectionAccounting() moves it from Deferred Penalty
 * Income into Penalty Income at that point).
 *
 * accrue() is called from two places: automatically from
 * Payment::allocateToSchedule() right before a payment is applied (scoped
 * to that one loan, as of the payment date -- this is the primary,
 * client-facing trigger), and from the manual "Penalty Accruals" screen
 * (PenaltyAccrualController::post(), portfolio-wide, no loan filter) that
 * staff can still use to see/book accrued-but-uncollected penalty exposure
 * ahead of any payment. Both share this one method, so whichever runs
 * first "wins" -- the NOT EXISTS guard below prevents double-charging.
 */
class PenaltyAccrualService
{
    /** Days of grace after due_date before a penalty is charged. */
    public const GRACE_DAYS = 5;

    /** Lifetime cap on penalty charges per loan -- see class doc-comment. */
    public const MAX_PENALTIES_PER_LOAN = 3;

    /**
     * Every overdue installment (optionally scoped to one loan) that
     * still has an unpaid shortfall and doesn't already have a 'Charged'
     * or 'Paid' penalties row against it, with the penalty amount that
     * shortfall would roll forward as of $asOfDate, and which row (its
     * own, or the next installment) that penalty lands on -- after
     * applying the per-loan once-a-month and lifetime caps, so at most
     * one row per loan comes back per call.
     */
    public static function chargeableInstallments(string $asOfDate, ?int $loanId = null): array
    {
        $db = Database::connection();
        $sql = "SELECT ls.id AS schedule_id, ls.loan_id, ls.installment_no, ls.due_date, ls.total_due, ls.total_paid,
                       nxt.id AS target_schedule_id,
                       l.borrower_id, l.branch_id, l.loan_no, l.penalty_rate,
                       CONCAT(b.first_name,' ',b.last_name) AS borrower_name,
                       DATEDIFF(?, ls.due_date) AS days_overdue,
                       (ls.total_due - ls.total_paid) AS base_amount
                FROM loan_schedules ls
                JOIN loans l ON l.id = ls.loan_id
                JOIN borrowers b ON b.id = l.borrower_id
                LEFT JOIN loan_schedules nxt ON nxt.loan_id = ls.loan_id AND nxt.installment_no = ls.installment_no + 1
                WHERE l.loan_status IN ('Active', 'Current', 'Released')
                  AND ls.total_due > ls.total_paid
                  AND DATEDIFF(?, ls.due_date) > ?";
        $params = [$asOfDate, $asOfDate, self::GRACE_DAYS];

        if ($loanId !== null) {
            $sql .= " AND l.id = ?";
            $params[] = $loanId;
        }

        $sql .= " AND NOT EXISTS (
                      SELECT 1 FROM penalties p
                      WHERE p.schedule_id = ls.id AND p.status IN ('Charged', 'Paid')
                  )
                  AND (SELECT COUNT(*) FROM penalties p2 WHERE p2.loan_id = l.id AND p2.status IN ('Charged', 'Paid')) < ?
                  AND NOT EXISTS (
                      SELECT 1 FROM penalties p3
                      WHERE p3.loan_id = l.id AND p3.status IN ('Charged', 'Paid')
                        AND DATE_FORMAT(p3.penalty_date, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
                  )
                  ORDER BY ls.due_date";
        $params[] = self::MAX_PENALTIES_PER_LOAN;
        $params[] = $asOfDate;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['days_overdue'] = (int) $row['days_overdue'];
            $row['base_amount'] = round((float) $row['base_amount'], 2);
            $row['penalty_rate'] = (float) $row['penalty_rate'];
            $row['penalty_amount'] = round($row['base_amount'] * $row['penalty_rate'] / 100, 2);
            $row['target_schedule_id'] = $row['target_schedule_id'] !== null ? (int) $row['target_schedule_id'] : (int) $row['schedule_id'];
        }
        unset($row);

        $rows = array_values(array_filter($rows, fn ($r) => $r['penalty_amount'] > 0));

        // The SQL caps above only see penalties already in the table, so
        // several installments on the same loan can still both qualify in
        // one run (neither has a penalties row yet). Keep only the
        // earliest-due one per loan -- rows are already due_date-ordered.
        $seenLoans = [];
        return array_values(array_filter($rows, function ($r) use (&$seenLoans) {
            if (isset($seenLoans[$r['loan_id']])) {
                return false;
            }
            $seenLoans[$r['loan_id']] = true;
            return true;
        }));
    }

    /**
     * Charges every chargeable installment as of $asOfDate (optionally
     * scoped to one loan): inserts a 'Charged' penalties row per source
     * installment (audit trail of *which* shortfall triggered it), rolls
     * the amount onto the target installment's penalty_due/total_due
     * (the next installment, or the same row if there is none), rolls
     * forward opening/closing balances downstream of that row, and posts
     * one combined accrual journal (Dr Penalty Receivable / Cr Deferred
     * Penalty Income) for the total. Returns the installments charged --
     * empty if there was nothing to charge.
     */
    public static function accrue(string $asOfDate, ?int $userId, ?int $loanId = null): array
    {
        $installments = self::chargeableInstallments($asOfDate, $loanId);
        $total = round(array_sum(array_column($installments, 'penalty_amount')), 2);

        if ($total <= 0) {
            return [];
        }

        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();
        $penalties = new Penalty();
        $loans = new Loan();

        $journal->post(
            'PENALTY_ACCRUAL',
            'penalties',
            null,
            generate_reference('PEN'),
            'Penalty charges raised as at ' . $asOfDate,
            [
                ['account_id' => $accounts->idByCode('1040'), 'debit' => $total, 'credit' => 0],
                ['account_id' => $accounts->idByCode('2050'), 'debit' => 0, 'credit' => $total],
            ],
            $userId,
            $asOfDate,
            'Manual'
        );

        foreach ($installments as $line) {
            $penalties->create([
                'loan_id' => $line['loan_id'],
                'borrower_id' => $line['borrower_id'],
                'schedule_id' => $line['schedule_id'],
                'penalty_no' => generate_reference('PNL'),
                'penalty_date' => $asOfDate,
                'base_amount' => $line['base_amount'],
                'penalty_rate' => $line['penalty_rate'],
                'penalty_amount' => $line['penalty_amount'],
                'reason' => 'Installment #' . $line['installment_no'] . ' ' . $line['days_overdue']
                    . ' days overdue as at ' . $asOfDate . ' -- ' . $line['base_amount'] . ' unpaid, '
                    . ($line['target_schedule_id'] === $line['schedule_id']
                        ? 'rolled onto this same installment (no next one)'
                        : 'rolled onto installment #' . ($line['installment_no'] + 1)),
                'status' => 'Charged',
                'charged_by' => $userId,
            ]);

            $target = $loans->schedule($line['loan_id']);
            $targetRow = null;
            foreach ($target as $row) {
                if ((int) $row['id'] === $line['target_schedule_id']) {
                    $targetRow = $row;
                    break;
                }
            }

            $newPenaltyDue = round((float) $targetRow['penalty_due'] + $line['penalty_amount'], 2);
            $loans->updateScheduleRow($line['target_schedule_id'], [
                'penalty_due' => $newPenaltyDue,
                'total_due' => round((float) $targetRow['total_due'] + $line['penalty_amount'], 2),
            ]);
        }

        return $installments;
    }
}
