<?php

/**
 * Confirms freshly placed Collexia mandates and sets each installment to its
 * exact date and amount -- the automatic equivalent of clicking "Check Final
 * Fate" on every debit order with a mandate still "Load Pending". Collexia
 * confirms a new mandate a short while after it is loaded; this picks up
 * whatever the Register & Send button was not able to wait for. See
 * CollexiaMandateFinalizer.
 *
 * Cheap to run often -- it only touches mandates that are still Load Pending
 * (usually none), so one crontab line every 5 minutes is fine:
 *
 *   *\/5 * * * * /usr/bin/php /path/to/bin/finalize_pending_collexia_mandates.php >> storage/logs/collexia_finalize.log 2>&1
 *
 * Needs the shared Collexia front-end username to be configured (Settings >
 * Collexia), since a cron has no logged-in user to borrow one from.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\CollexiaSetting;
use App\Services\CollexiaMandateFinalizer;

$settings = new CollexiaSetting();
if (!$settings->isEnabled() || !$settings->isConfigured()) {
    $summary = sprintf("[%s] Skipped -- Collexia API integration is not enabled/configured.\n", date('Y-m-d H:i:s'));
    \App\Core\JobHeartbeat::ping('finalize_pending_collexia_mandates', $summary, 15);
    echo $summary;
    exit(0);
}

$user = $settings->frontEndUsername();
if ($user === '') {
    $summary = sprintf("[%s] Skipped -- no Collexia front-end username is configured.\n", date('Y-m-d H:i:s'));
    \App\Core\JobHeartbeat::ping('finalize_pending_collexia_mandates', $summary, 15);
    echo $summary;
    exit(0);
}

$result = (new CollexiaMandateFinalizer())->finalizeAllPending($user);

$summary = sprintf(
    "[%s] %d debit order(s) with pending mandates: %d registered, %d still pending, %d failed.\n",
    date('Y-m-d H:i:s'),
    $result['debit_orders'],
    $result['registered'],
    $result['pending'],
    $result['failed']
);
foreach ($result['warnings'] as $warning) {
    $summary .= '  ' . $warning . "\n";
}

\App\Core\JobHeartbeat::ping('finalize_pending_collexia_mandates', trim($summary), 15);
if ($result['debit_orders'] > 0 || $result['warnings']) {
    echo $summary;
}
