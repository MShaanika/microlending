<?php

/**
 * Rolling 30-day preview generator for employer pay-cycle-aware debit
 * order collection dates (see database/pay_cycle_module.sql). Scans
 * unpaid installments due within the next 30 days, computes a proposed
 * collection date for every borrower whose employer/override resolves
 * to a pay-cycle policy, and writes PENDING_REVIEW rows for staff to
 * review and submit for maker-checker approval.
 *
 * Does NOT call Collexia. Does NOT touch loan_schedules.due_date.
 * Idempotent -- a same-day re-run with nothing changed creates zero new
 * rows (see CollectionDateAdjustmentService::generateRollingPreview()).
 *
 *   (once daily) /usr/bin/php /path/to/bin/generate_collection_date_adjustments.php >> storage/logs/collection_date_adjustments.log 2>&1
 *
 * NOT YET ADDED TO CRONTAB -- per the standing rule on scheduled jobs
 * touching money/collection-related data, this needs an explicit
 * go-ahead for its first scheduled run, same as
 * sync_collexia_mandate_status.php before it. Safe to run manually any
 * time in the meantime (also reachable from the UI's own "Generate /
 * Refresh Preview" button, which calls the exact same service method).
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Services\CollectionDateAdjustmentService;

$service = new CollectionDateAdjustmentService();
$through = new DateTimeImmutable('+30 days');

$result = $service->generateRollingPreview($through, null);

if ($result['batch_id'] === null) {
    echo date('Y-m-d H:i:s') . " No adjustments needed -- nothing changed since the last run.\n";
} else {
    echo date('Y-m-d H:i:s') . ' Batch #' . $result['batch_id'] . ' created with ' . count($result['adjustment_ids']) . " adjustment row(s).\n";
}
