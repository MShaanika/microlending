<?php

/**
 * Daily background job that advances payment_status/aging_bucket/
 * collection_status/credit_status for every Active loan -- see
 * ArrearsService::refreshLoanStatus(). This is the only thing that moves a
 * loan's aging bucket purely from elapsed time (e.g. a loan nobody has paid
 * or acted on for another 30 days needs to advance from "1-29" to "30-59"
 * even though nothing happened today); a payment is the other trigger
 * (Payment::finalizeAllocation()), but that alone would leave un-paid loans
 * stuck at a stale bucket indefinitely. Meant to run once a day, e.g.:
 *
 *   0 2 * * * /usr/bin/php /path/to/bin/sweep_loan_status.php >> storage/logs/loan_status_sweep.log 2>&1
 *
 * Safe to run more than once on the same day, or to catch up after a missed
 * day -- refreshLoanStatus() is a no-op for any loan whose computed state
 * already matches what's stored.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Services\ArrearsService;

$asOfDate = date('Y-m-d');
$sourceEventKey = 'SWEEP:' . $asOfDate;

$db = Database::connection();
$loanIds = $db->query("SELECT id FROM loans WHERE loan_status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);

$touched = 0;
foreach ($loanIds as $loanId) {
    ArrearsService::refreshLoanStatus((int) $loanId, $asOfDate, null, 'Sweep', $sourceEventKey);
    $touched++;
}

$summary = sprintf(
    "[%s] Swept loan status for %d active loan(s) as at %s.\n",
    date('Y-m-d H:i:s'),
    $touched,
    $asOfDate
);
\App\Core\JobHeartbeat::ping('sweep_loan_status', $summary, 1440);
echo $summary;
