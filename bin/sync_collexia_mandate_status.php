<?php

/**
 * Pulls Collexia's current mandate.status (Mandate Enquiry, EnDO V3 spec
 * 6.3) for every debit order that could still change locally, and updates
 * debit_orders.status to match -- the scheduled equivalent of clicking
 * "Sync Status" on each one by hand. See CollexiaMandateStatusSyncService
 * for the actual mapping/roll-up logic and DebitOrder::dueForCollexiaStatusSync()
 * for the eligibility query.
 *
 * A reasonable crontab entry, alongside the existing payments-download cron
 * (bin/download_collexia_payments.php):
 *
 *   0 7,12,17 * * * /usr/bin/php /path/to/bin/sync_collexia_mandate_status.php >> storage/logs/collexia_status_sync.log 2>&1
 *
 * Offset from the payments cron's own times (6/10/15/20) so the two never
 * race on the same debit order. Safe to run as often as desired --
 * mandateEnquiry() is read-only and each run just re-syncs whatever is
 * still Active/Suspended; already-Cancelled/Completed debit orders drop out
 * of the eligible set on their own. A no-op (not an error) if the
 * integration isn't configured/enabled yet, same as every other Collexia
 * API action in the app.
 *
 * NOT yet installed as an actual crontab entry -- that's a separate,
 * explicit step once the schedule above is confirmed.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Services\CollexiaMandateStatusSyncService;

$settings = new CollexiaSetting();
if (!$settings->isEnabled() || !$settings->isConfigured()) {
    $skipSummary = sprintf("[%s] Skipped -- Collexia API integration is not enabled/configured.\n", date('Y-m-d H:i:s'));
    \App\Core\JobHeartbeat::ping('sync_collexia_mandate_status', $skipSummary, 360);
    echo $skipSummary;
    exit(0);
}

$debitOrders = new DebitOrder();
$service = new CollexiaMandateStatusSyncService();

$due = $debitOrders->dueForCollexiaStatusSync();
$synced = 0;
$changed = 0;
$failed = 0;

foreach ($due as $debitOrder) {
    $previousStatus = $debitOrder['status'];
    $result = $service->syncDebitOrder($debitOrder);

    if (!$result['synced']) {
        $failed++;
        echo sprintf("[%s] Debit order #%d: %s\n", date('Y-m-d H:i:s'), $debitOrder['id'], $result['error']);
        continue;
    }

    $synced++;
    if ($result['mandate_status'] !== null && $result['mandate_status'] !== $previousStatus) {
        $changed++;
        echo sprintf(
            "[%s] Debit order #%d: %s -> %s\n",
            date('Y-m-d H:i:s'),
            $debitOrder['id'],
            $previousStatus,
            $result['mandate_status']
        );
    }
}

$summary = sprintf(
    "[%s] %d due, %d synced, %d status change(s), %d failed.\n",
    date('Y-m-d H:i:s'),
    count($due),
    $synced,
    $changed,
    $failed
);
\App\Core\JobHeartbeat::ping('sync_collexia_mandate_status', $summary, 360);
echo $summary;
