<?php

namespace App\Services;

use App\Core\Database;

/**
 * One-time historical catch-up for the loan status dimensions restructuring
 * (see database/loan_status_dimensions.sql and
 * ArrearsService::refreshLoanStatus()). Every existing loan reads back
 * column DEFAULTS (Current/Current/Normal Collection/Performing) until this
 * runs -- it must run before the real-time hooks
 * (Payment::finalizeAllocation(), bin/sweep_loan_status.php) are relied on
 * for anything, otherwise a loan with genuine historical arrears would
 * silently show as "Current" until its next payment or the next sweep.
 *
 * Also normalizes existing loan_status='Current' rows back to 'Active' --
 * see Payment::finalizeAllocation()'s docblock for why that lifecycle value
 * is retired (every IN('Active','Current',...) check elsewhere already
 * treats them as equivalent, so this has no functional effect on those
 * reads; it's purely closing out a value nothing will write again).
 *
 * Safe to re-run: refreshLoanStatus() is itself idempotent (no-op per loan
 * if nothing changed), and the loan_status normalization only ever touches
 * rows still sitting at 'Current'.
 */
class LoanStatusBackfillService
{
    /** @return array{loan_count: int, changed_count: int, current_status_count: int} */
    public static function preview(): array
    {
        return self::compute(false, null);
    }

    /** @return array{loan_count: int, changed_count: int, current_status_count: int} */
    public static function run(?int $userId): array
    {
        return self::compute(true, $userId);
    }

    private static function compute(bool $apply, ?int $userId): array
    {
        $db = Database::connection();
        $asOfDate = date('Y-m-d');

        $loans = $db->query(
            "SELECT id, payment_status, aging_bucket, collection_status, credit_status FROM loans"
        )->fetchAll();

        $changedCount = 0;
        foreach ($loans as $loan) {
            $loanId = (int) $loan['id'];
            $computed = ArrearsService::computeStatusDimensions($loanId, $asOfDate);
            $changed = $computed['payment_status'] !== $loan['payment_status']
                || $computed['aging_bucket'] !== $loan['aging_bucket']
                || $computed['collection_status'] !== $loan['collection_status']
                || $computed['credit_status'] !== $loan['credit_status'];

            if (!$changed) {
                continue;
            }

            $changedCount++;
            if ($apply) {
                ArrearsService::refreshLoanStatus($loanId, $asOfDate, $userId, 'Backfill', 'BACKFILL:' . $asOfDate);
            }
        }

        $currentStatusCount = (int) $db->query("SELECT COUNT(*) FROM loans WHERE loan_status = 'Current'")->fetchColumn();

        if ($apply && $currentStatusCount > 0) {
            $db->exec("UPDATE loans SET loan_status = 'Active' WHERE loan_status = 'Current'");
        }

        return [
            'loan_count' => count($loans),
            'changed_count' => $changedCount,
            'current_status_count' => $currentStatusCount,
        ];
    }
}
