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
$installments = InterestAccrualService::accrue($asOfDate, null);

if (empty($installments)) {
    echo "[" . date('Y-m-d H:i:s') . "] No interest to accrue as at $asOfDate.\n";
    exit(0);
}

$total = round(array_sum(array_column($installments, 'interest_amount')), 2);

echo sprintf(
    "[%s] Accrued interest for %d installment(s) as at %s, total %s.\n",
    date('Y-m-d H:i:s'),
    count($installments),
    $asOfDate,
    number_format($total, 2)
);
