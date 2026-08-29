<?php

/**
 * Executes every active retention policy (Part 44-48) -- deletes rows
 * past their retention window, skipping anything under legal hold,
 * recording a retention_runs row for each execution.
 *
 * Deliberately does not touch form_drafts, even though a
 * form_drafts_expiry policy exists (kept for visibility on the
 * Retention dashboard) -- that table needs cascading cleanup of
 * draft_documents rows and uploaded files, which
 * bin/sweep_draft_expiry.php already does correctly. Running both
 * against the same table would be redundant at best and could race at
 * worst. RetentionService::execute() itself also refuses any table not
 * on its own allowlist, so this exclusion is enforced twice.
 *
 *   0 3 * * * /usr/bin/php /path/to/bin/evaluate_retention.php >> storage/logs/retention.log 2>&1
 *
 * Safe to run more than once -- a row already deleted is simply no
 * longer eligible on the next run.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\RetentionPolicy;
use App\Services\RetentionService;

$model = new RetentionPolicy();
$totalDeleted = 0;
$totalHeld = 0;
$policiesRun = 0;

foreach ($model->activePolicies() as $policy) {
    if ($policy['resource_table'] === 'form_drafts') {
        continue; // see docblock -- bin/sweep_draft_expiry.php owns this table
    }

    $result = RetentionService::execute($policy, null);
    $totalDeleted += $result['deleted'];
    $totalHeld += $result['held'];
    $policiesRun++;
}

echo sprintf(
    "[%s] Retention sweep: %d polic(ies) run, %d row(s) deleted, %d held by legal hold.\n",
    date('Y-m-d H:i:s'),
    $policiesRun,
    $totalDeleted,
    $totalHeld
);
