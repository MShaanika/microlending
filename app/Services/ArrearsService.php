<?php

namespace App\Services;

use App\Core\Database;
use App\Models\BadDebtProvision;

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
 *
 * refreshLoanStatus() is the write side of the "Status Flow and Accounting
 * Integration Guide" spec: it persists payment_status/aging_bucket/
 * collection_status/credit_status onto loans (stored columns, not computed
 * live) and logs every real transition to arrears_status_transitions. It's
 * called both after a payment is allocated (Payment::finalizeAllocation())
 * and by the daily bin/sweep_loan_status.php cron, which is what actually
 * advances aging_bucket purely from elapsed time on loans nobody is
 * currently paying.
 */
class ArrearsService
{
    /** Placeholder provisioning rates for the new 5-bucket scheme -- NOT
     *  confirmed Finance-approved figures. Compressing from the old 6-bucket
     *  scheme to 5 buckets also moves full (100%) provisioning from day 180
     *  to day 90; flag this to Finance explicitly, not just the percentages,
     *  before relying on these for real financial statements. Current/1-29
     *  are always 0: the bad_debts table's aging_bucket ENUM only accepts
     *  30-59 and up, so provisioning never applies before a loan is 30 days
     *  overdue. */
    public const PROVISION_RATES = [
        'Current' => 0.0,
        '1-29' => 0.0,
        '30-59' => 25.0,
        '60-89' => 60.0,
        '90+' => 100.0,
    ];

    public static function agingBucket(int $daysInArrears): string
    {
        if ($daysInArrears <= 0) return 'Current';
        if ($daysInArrears <= 29) return '1-29';
        if ($daysInArrears <= 59) return '30-59';
        if ($daysInArrears <= 89) return '60-89';
        return '90+';
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

    /**
     * Maps bad_debts.status (the real, already event-driven column) to the
     * spec's Recovery Status vocabulary. Recovery status is deliberately
     * not a stored column of its own -- see loan_status_dimensions.sql.
     */
    public static function recoveryStatusLabel(?string $badDebtStatus): string
    {
        return match ($badDebtStatus) {
            'Written Off', 'Provisioned', 'Open' => 'Recovery Queue',
            'Under Recovery' => 'Bad Debt Recovery',
            'Recovered', 'Closed' => 'Fully Recovered',
            default => 'Not Applicable',
        };
    }

    /**
     * Days in arrears for a single loan as of $asOfDate, computed directly
     * (not via overdueLoans(), which scans every loan in the portfolio --
     * wasteful when refreshLoanStatus() only needs one loan's number after a
     * payment). Zero if nothing is currently overdue.
     */
    private static function daysInArrearsForLoan(int $loanId, string $asOfDate): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT DATEDIFF(?, MIN(ls.due_date)) AS days_in_arrears
             FROM loan_schedules ls
             WHERE ls.loan_id = ?
               AND ls.due_date <= ?
               AND ls.total_due > ls.total_paid"
        );
        $stmt->execute([$asOfDate, $loanId, $asOfDate]);
        $days = (int) ($stmt->fetchColumn() ?: 0);
        return max(0, $days);
    }

    /**
     * Whether this loan has a currently-active promise to pay. A promise
     * has no automatic lapse in this app (CollectionsController only ever
     * flips it to Kept/Broken/Cancelled via manual staff action), so
     * "active" is defined here as still-Pending AND not yet past its
     * promised date -- otherwise a stale, unactioned promise would keep a
     * loan in "Recovery Arrangement" forever.
     */
    private static function hasActivePromise(int $loanId, string $asOfDate): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT 1 FROM payment_promises
             WHERE loan_id = ? AND status = 'Pending' AND promise_date >= ?
             LIMIT 1"
        );
        $stmt->execute([$loanId, $asOfDate]);
        return (bool) $stmt->fetchColumn();
    }

    private static function creditStatusForBucket(string $agingBucket): string
    {
        return match ($agingBucket) {
            'Current', '1-29' => 'Performing',
            '30-59' => 'Watchlist',
            default => 'Non-Performing', // 60-89, 90+
        };
    }

    /**
     * Pure computation, no writes: what payment_status/aging_bucket/
     * collection_status/credit_status SHOULD be for this loan as of
     * $asOfDate. Shared by refreshLoanStatus() (which persists the result)
     * and LoanStatusBackfillService's preview (which needs an accurate
     * dry-run with no side effects).
     */
    public static function computeStatusDimensions(int $loanId, string $asOfDate): array
    {
        $daysInArrears = self::daysInArrearsForLoan($loanId, $asOfDate);
        $paymentStatus = $daysInArrears > 0 ? 'In Arrears' : 'Current';
        $agingBucket = self::agingBucket($daysInArrears);

        $collectionStatus = 'Normal Collection';
        if ($paymentStatus === 'In Arrears') {
            $collectionStatus = self::hasActivePromise($loanId, $asOfDate) ? 'Recovery Arrangement' : 'Arrears Recovery';
        }

        $provisionAmount = (new BadDebtProvision())->provisionForLoan($loanId);
        $creditStatus = $provisionAmount > 0.009 ? 'Impaired' : self::creditStatusForBucket($agingBucket);

        return [
            'days_in_arrears' => $daysInArrears,
            'payment_status' => $paymentStatus,
            'aging_bucket' => $agingBucket,
            'collection_status' => $collectionStatus,
            'credit_status' => $creditStatus,
        ];
    }

    /**
     * Recomputes and persists payment_status/aging_bucket/collection_status/
     * credit_status for one loan as of $asOfDate, and logs a row to
     * arrears_status_transitions if anything actually changed since the
     * last check (a no-op call costs one extra SELECT, nothing more).
     *
     * $source is 'Payment' (called right after a payment is allocated),
     * 'Sweep' (the daily cron -- the only thing that advances aging_bucket
     * purely from elapsed time), or 'Backfill' (the one-time historical
     * catch-up). $sourceEventKey is a free-form reference for the audit
     * trail, e.g. a payment_no or 'SWEEP:2026-08-28'.
     */
    public static function refreshLoanStatus(
        int $loanId,
        string $asOfDate,
        ?int $userId,
        string $source,
        ?string $sourceEventKey = null
    ): void {
        $db = Database::connection();

        $loan = $db->prepare(
            "SELECT id, borrower_id, payment_status, aging_bucket, collection_status, credit_status
             FROM loans WHERE id = ?"
        );
        $loan->execute([$loanId]);
        $current = $loan->fetch();
        if (!$current) {
            return;
        }

        $computed = self::computeStatusDimensions($loanId, $asOfDate);
        $daysInArrears = $computed['days_in_arrears'];
        $newPaymentStatus = $computed['payment_status'];
        $newAgingBucket = $computed['aging_bucket'];
        $newCollectionStatus = $computed['collection_status'];
        $newCreditStatus = $computed['credit_status'];

        $changed = $newPaymentStatus !== $current['payment_status']
            || $newAgingBucket !== $current['aging_bucket']
            || $newCollectionStatus !== $current['collection_status']
            || $newCreditStatus !== $current['credit_status'];

        if (!$changed) {
            return;
        }

        $update = $db->prepare(
            "UPDATE loans SET payment_status = ?, aging_bucket = ?, collection_status = ?, credit_status = ? WHERE id = ?"
        );
        $update->execute([$newPaymentStatus, $newAgingBucket, $newCollectionStatus, $newCreditStatus, $loanId]);

        $eventType = match (true) {
            $current['payment_status'] === 'Current' && $newPaymentStatus === 'In Arrears' => 'ARREARS_ENTERED',
            $current['payment_status'] === 'In Arrears' && $newPaymentStatus === 'Current' => 'ARREARS_CLEARED',
            $newAgingBucket !== $current['aging_bucket'] => 'BUCKET_ADVANCED',
            $newCollectionStatus !== $current['collection_status'] => 'COLLECTION_STATUS_CHANGED',
            default => 'CREDIT_STATUS_CHANGED',
        };

        $insert = $db->prepare(
            "INSERT INTO arrears_status_transitions
                (loan_id, borrower_id, event_type, event_date,
                 from_payment_status, to_payment_status, from_aging_bucket, to_aging_bucket,
                 from_collection_status, to_collection_status, days_in_arrears, source, source_event_key, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            $loanId,
            $current['borrower_id'],
            $eventType,
            $asOfDate,
            $current['payment_status'],
            $newPaymentStatus,
            $current['aging_bucket'],
            $newAgingBucket,
            $current['collection_status'],
            $newCollectionStatus,
            $daysInArrears,
            $source,
            $sourceEventKey,
            $userId,
        ]);
    }
}
