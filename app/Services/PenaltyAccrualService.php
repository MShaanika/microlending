<?php

namespace App\Services;

use App\Core\Database;

/**
 * Identifies overdue, not-yet-penalized installments and computes the
 * penalty due on each. A penalty is charged once per installment (the
 * first time this run sees it overdue) -- it does not compound daily.
 *
 * This is the accrual half of the deferred-income pattern: charging a
 * penalty here only raises a Penalty Receivable against Deferred Penalty
 * Income (see PenaltyAccrualController::post()). It is not recognized as
 * P&L income until actually collected (Payment::postCollectionAccounting()
 * moves it from Deferred Penalty Income into Penalty Income at that point).
 */
class PenaltyAccrualService
{
    /** Days of grace after due_date before a penalty is charged. */
    public const GRACE_DAYS = 0;

    /**
     * Every overdue, unpaid installment that does not already have a
     * 'Charged' or 'Paid' penalties row against it, with the penalty
     * amount that would be charged as of $asOfDate.
     */
    public static function chargeableInstallments(string $asOfDate): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT ls.id AS schedule_id, ls.loan_id, ls.installment_no, ls.due_date, ls.total_due,
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
               AND DATEDIFF(?, ls.due_date) > ?
               AND NOT EXISTS (
                   SELECT 1 FROM penalties p
                   WHERE p.schedule_id = ls.id AND p.status IN ('Charged', 'Paid')
               )
             ORDER BY ls.due_date"
        );
        $stmt->execute([$asOfDate, $asOfDate, self::GRACE_DAYS]);
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
}
