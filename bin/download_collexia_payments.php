<?php

/**
 * Pulls Collexia's Download Payments response (EnDO V3 spec 7.4) and
 * reconciles it into debit_order_collections/payments, same posting rules
 * as a manually-uploaded Successful Transactions report -- see
 * CollexiaPaymentReconciliationService. Collexia recommends pulling four
 * times a day; a reasonable crontab entry:
 *
 *   0 6,10,15,20 * * * /usr/bin/php /path/to/bin/download_collexia_payments.php >> storage/logs/collexia_payments.log 2>&1
 *
 * Safe to run more than once on the same day, or to run this alongside the
 * manual "Download Payments from Collexia" button on /debit-order-collections
 * -- DebitOrderCollection::alreadyPosted() guards against double-posting
 * the same installment either way. A no-op (not an error) if the
 * integration isn't configured/enabled yet, same as every other Collexia
 * API action in the app.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\CollexiaSetting;
use App\Services\CollexiaEndoApiClient;
use App\Services\CollexiaPaymentReconciliationService;

$settings = new CollexiaSetting();
if (!$settings->isEnabled() || !$settings->isConfigured()) {
    $skipSummary = sprintf("[%s] Skipped -- Collexia API integration is not enabled/configured.\n", date('Y-m-d H:i:s'));
    // Still a heartbeat: the cron entry itself fired on schedule, it's
    // the (legitimate) business decision to leave Collexia disabled
    // that's causing the no-op, not a missed job.
    \App\Core\JobHeartbeat::ping('download_collexia_payments', $skipSummary, 360);
    echo $skipSummary;
    exit(0);
}

try {
    $response = (new CollexiaEndoApiClient())->downloadPayments();
} catch (\RuntimeException $e) {
    echo sprintf("[%s] Failed: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    exit(1);
}

$result = (new CollexiaPaymentReconciliationService())->reconcile($response, null, null);

$summary = sprintf(
    "[%s] %d row(s) downloaded, %d matched to a mandate, %d payment(s) posted (import #%d).\n",
    date('Y-m-d H:i:s'),
    $result['total'],
    $result['matched'],
    $result['posted'],
    $result['import_id']
);
\App\Core\JobHeartbeat::ping('download_collexia_payments', $summary, 360);
echo $summary;
