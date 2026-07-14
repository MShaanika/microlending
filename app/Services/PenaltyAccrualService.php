<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Penalty;

/**
 * Identifies overdue, not-yet-penalized installments and computes the
 * penalty due on each. A penalty is charged once per installment (the
 * first time this run sees it overdue) -- it does not compound daily.
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

    /**
     * Every overdue, unpaid installment (optionally scoped to one loan)
     * that does not already have a 'Charged' or 'Paid' penalties row
     * against it, with the penalty amount that would be charged as of
     * $asOfDate.
     */
    public static function chargeableInstallments(string $asOfDate, ?int $loanId = null): array
    {
        $db = Database::connection();
        $sql = "SELECT ls.id AS schedule_id, ls.loan_id, ls.installment_no, ls.due_date, ls.total_due,
                       l.borrower_id, l.branch_id, l.loan_no, l.penalty_rate,
                       CONCAT(b.first_name,' ',b.last_name) AS borrower_name,
                       DATEDIFF(?, ls.due_date) AS days_overdue,
                       (ls.principal_due - ls.principal_paid)
                       + (ls.interest_due - ls.interest_paid)
                       + (ls.fees_due - ls.fees_paid)
                       + (ls.namfisa_levy_due - ls.namfisa_levy_paid)
                       + (ls.duty_stamp_due - ls.duty_stamp_paid) AS base_amount
                FROM loan_schedules ls
                JOIN loans l ON l.id = ls.loan_id
                JOIN borrowers b ON b.id = l.borrower_id
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
                  ORDER BY ls.due_date";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['days_overdue'] = (int) $row['days_overdue'];
            $row['base_amount'] = round((float) $row['base_amount'], 2);
            $row['penalty_rate'] = (float) $row['penalty_rate'];
            $row['penalty_amount'] = round($row['base_amount'] * $row['penalty_rate'] / 100, 2);
        }
        unset($row);

        return array_values(array_filter($rows, fn ($r) => $r['penalty_amount'] > 0));
    }

    /**
     * Charges every chargeable installment as of $asOfDate (optionally
     * scoped to one loan): inserts a 'Charged' penalties row per
     * installment, raises loan_schedules.penalty_due/total_due, and posts
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
                'reason' => 'Installment #' . $line['installment_no'] . ' ' . $line['days_overdue'] . ' days overdue as at ' . $asOfDate,
                'status' => 'Charged',
                'charged_by' => $userId,
            ]);

            // penalty_due is only ever set here, so it is guaranteed 0 going
            // into this run -- no need to re-fetch the row first.
            $loans->updateScheduleRow((int) $line['schedule_id'], [
                'penalty_due' => $line['penalty_amount'],
                'total_due' => round((float) $line['total_due'] + $line['penalty_amount'], 2),
            ]);
        }

        return $installments;
    }
}
