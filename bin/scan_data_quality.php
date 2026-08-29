<?php

/**
 * Runs every active data quality rule (Part 28-33) -- upserts issues for
 * rows still failing, auto-resolves ones that no longer do. Never
 * writes to the records it checks.
 *
 *   0 4 * * * /usr/bin/php /path/to/bin/scan_data_quality.php >> storage/logs/data_quality.log 2>&1
 *
 * Safe to run more than once -- upsert/auto-resolve is idempotent.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Services\DataQualityService;

$summary = DataQualityService::scan();

$totalFailing = array_sum(array_column($summary, 'failing'));
$totalResolved = array_sum(array_column($summary, 'auto_resolved'));

echo sprintf(
    "[%s] Data quality scan: %d rule(s) run, %d issue(s) currently failing, %d auto-resolved.\n",
    date('Y-m-d H:i:s'),
    count($summary),
    $totalFailing,
    $totalResolved
);
