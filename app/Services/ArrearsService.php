<?php

namespace App\Services;

use App\Core\Database;

/**
 * Computes loan arrears/aging directly from loan_schedules (due vs paid),
 * rather than trusting any pre-set status column, since nothing else in
 * the app currently maintains one. This is the single source of truth
 * used by both bad-debt provisioning and the write-off workflow.
 *
 * "Outstanding balance" here means the portion actually sitting in the
 * Loans Receivable GL control account for that loan: principal + NAMFISA
 * levy + duty stamp still unpaid. Interest/fees/penalties are deliberately
 * excluded -- this system recognizes them as income only when collected
 * (cash basis) and never books them as a receivable, so there is nothing
 * on the balance sheet for those to write off against.
 */
class ArrearsService
{
    /** Default provisioning rate per aging bucket -- configurable defaults,
     *  not confirmed NAMFISA-mandated rates. Adjust here if the client
     *  specifies different bands. Current/1-30 are always 0: the bad_debts
     *  table's aging_bucket ENUM only accepts 31-60 and up, so provisioning
     *  never applies before a loan is 31 days overdue. */
    public const PROVISION_RATES = [
        'Current' => 0.0,
        '1-30' => 0.0,
        '31-60' => 25.0,
        '61-90' => 50.0,
        '91-180' => 75.0,
        '180+' => 100.0,
    ];

    public static function agingBucket(int $daysInArrears): string
    {
        if ($daysInArrears <= 0) return 'Current';
        if ($daysInArrears <= 30) return '1-30';
        if ($daysInArrears <= 60) return '31-60';
        if ($daysInArrears <= 90) return '61-90';
        if ($daysInArrears <= 180) return '91-180';
        return '180+';
    }

    /**
     * Every active/current loan with at least one overdue, not-fully-paid
     * installment as of $asOfDate, with its days in arrears, aging bucket,
     * and GL-recognized outstanding balance (principal + levy + stamp).
     */
    public static function overdueLoans(string $asOfDate): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT l.id AS loan_id, l.loan_no, l.borrower_id, l.branch_id,
                    CONCAT(b.first_name,' ',b.last_name) AS borrower_name,
                    MIN(ls.due_date) AS oldest_unpaid_due_date,
                    DATEDIFF(?, MIN(ls.due_date)) AS days_in_arrears,
                    SUM(ls.principal_due - ls.principal_paid) AS principal_outstanding,
                    SUM(ls.namfisa_levy_due - ls.namfisa_levy_paid) AS levy_outstanding,
                    SUM(ls.duty_stamp_due - ls.duty_stamp_paid) AS stamp_outstanding,
                    SUM(ls.total_due - ls.total_paid) AS total_outstanding
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             JOIN borrowers b ON b.id = l.borrower_id
             WHERE l.loan_status IN ('Active', 'Current', 'Released')
               AND ls.due_date <= ?
               AND ls.total_due > ls.total_paid
             GROUP BY l.id
             HAVING days_in_arrears > 0
             ORDER BY days_in_arrears DESC"
        );
        $stmt->execute([$asOfDate, $asOfDate]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['days_in_arrears'] = (int) $row['days_in_arrears'];
            $row['aging_bucket'] = self::agingBucket($row['days_in_arrears']);
            $row['outstanding_balance'] = round(
                (float) $row['principal_outstanding'] + (float) $row['levy_outstanding'] + (float) $row['stamp_outstanding'],
                2
            );
            $row['provision_rate'] = self::PROVISION_RATES[$row['aging_bucket']];
            $row['provision_amount'] = round($row['outstanding_balance'] * $row['provision_rate'] / 100, 2);
        }

        return $rows;
    }

    /**
     * Same computation, but only for a single loan (used by the write-off
     * screen to show the current outstanding balance).
     */
    public static function loanOutstanding(int $loanId, string $asOfDate): array
    {
        foreach (self::overdueLoans($asOfDate) as $row) {
            if ((int) $row['loan_id'] === $loanId) {
                return $row;
            }
        }
        return [
            'loan_id' => $loanId,
            'days_in_arrears' => 0,
            'aging_bucket' => 'Current',
            'outstanding_balance' => 0.0,
            'provision_rate' => 0.0,
            'provision_amount' => 0.0,
        ];
    }
}
