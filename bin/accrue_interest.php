<?php

/**
 * Daily background job for accrual-basis interest income: recognizes
 * Interest Income (and the matching Interest Receivable) for every
 * loan_schedules installment whose due date has arrived as of today and
 * hasn't already been accrued -- see InterestAccrualService. Meant to run
 * once a day via cron, before that day's accounting period could close, e.g.:
 *
 *   0 1 * * * /usr/bin/php /path/to/bin/accrue_interest.php >> storage/logs/interest_accrual.log 2>&1
 *
 * Safe to run more than once on the same day, or to catch up after a
 * missed day -- already-accrued installments are skipped, and each
 * journal is dated by the installment's own due date, not "today", so a
 * multi-day catch-up run still lands each installment's income in the
 * correct period.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Services\InterestAccrualService;

$asOfDate = date('Y-m-d');

try {
    $installments = InterestAccrualService::accrue($asOfDate, null);
} catch (\Throwable $e) {
    // AccountingJournal::post() throws if the accounting period for
    // $asOfDate is closed, or if a target account is missing -- without
    // this catch, that killed the whole cron run silently: no heartbeat
    // ping (so it looked merely "late" on the Health dashboard, not
    // failed), and the underlying reason only visible by digging through
    // php's own fatal-error output rather than this script's own log.
    echo sprintf("[%s] Failed to accrue interest as at %s: %s\n", date('Y-m-d H:i:s'), $asOfDate, $e->getMessage());
    exit(1);
}

if (empty($installments)) {
    echo "[" . date('Y-m-d H:i:s') . "] No interest to accrue as at $asOfDate.\n";
    exit(0);
}

$total = round(array_sum(array_column($installments, 'interest_amount')), 2);

$summary = sprintf(
    "[%s] Accrued interest for %d installment(s) as at %s, total %s.\n",
    date('Y-m-d H:i:s'),
    count($installments),
    $asOfDate,
    number_format($total, 2)
);
\App\Core\JobHeartbeat::ping('accrue_interest', $summary, 1440);
echo $summary;
