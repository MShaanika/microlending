<?php

/**
 * Polls Creditinfo for any credit bureau report/PDF still New/InProgress
 * (per the manual's async model -- a report exceeding the requested
 * timeout or "big report" threshold returns only a request token, to be
 * fetched later). Credit bureau reports are expected to resolve quickly,
 * so a short interval is reasonable; suggested crontab entry (every 2
 * minutes, written as a step range so this comment block doesn't
 * accidentally close itself):
 *
 *   0-58/2 * * * * /usr/bin/php /path/to/bin/poll_creditinfo_reports.php >> storage/logs/creditinfo_poll.log 2>&1
 *
 * Safe to run repeatedly -- a row already Finished/Error/Rejected/Timeout
 * is a terminal state and is never re-polled (see
 * CreditinfoReportCache::pending()). A no-op (not an error) if the
 * integration isn't configured/enabled, same as every other Creditinfo/
 * Collexia background job in this app.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\CreditinfoReportCache;
use App\Models\CreditinfoReportContent;
use App\Models\CreditinfoSetting;
use App\Services\CreditinfoApiException;
use App\Services\CreditinfoBureauClient;

$settings = new CreditinfoSetting();
if (!$settings->isEnabled() || !$settings->isConfigured()) {
    $skipSummary = sprintf("[%s] Skipped -- Creditinfo integration is not enabled/configured.\n", date('Y-m-d H:i:s'));
    \App\Core\JobHeartbeat::ping('poll_creditinfo_reports', $skipSummary, 15);
    echo $skipSummary;
    exit(0);
}

$reportCache = new CreditinfoReportCache();
$reportContent = new CreditinfoReportContent();
$bureau = new CreditinfoBureauClient();

$counts = ['polled' => 0, 'finished' => 0, 'errored' => 0];

foreach ($reportCache->pending() as $row) {
    $counts['polled']++;
    $content = $reportContent->findByReportCacheId((int) $row['id']);
    $token = $content['report_token'] ?? $row['request_id'];
    if (!$token) {
        continue;
    }

    try {
        $result = $bureau->pollCustomReportInfo((string) $token);
        $status = $result['data']['requestStatus'] ?? 'Unknown';

        if ($status === 'Finished') {
            $full = $bureau->pollCustomReport((string) $token);
            $reportData = $full['data'] ?? [];
            $reportContent->storeReport((int) $row['id'], $reportData['report'] ?? [], $reportData['requestId'] ?? $token);
            $reportCache->updateFields((int) $row['id'], ['report_status' => 'Finished']);
            (new \App\Models\LoanApplication())->updateRecord((int) $row['application_id'], ['credit_check_status' => 'Completed']);
            $counts['finished']++;
        } elseif (in_array($status, ['Error', 'Rejected', 'Timeout'], true)) {
            $reportCache->updateFields((int) $row['id'], ['report_status' => $status, 'last_error' => 'Creditinfo report ' . $status]);
            (new \App\Models\LoanApplication())->updateRecord((int) $row['application_id'], ['credit_check_status' => 'Error']);
            $counts['errored']++;
        } else {
            $reportCache->updateFields((int) $row['id'], ['report_status' => $status]);
        }
    } catch (CreditinfoApiException $e) {
        $reportCache->updateFields((int) $row['id'], ['last_error' => $e->getMessage()]);
        \App\Core\Audit::log('Uncertain', 'Creditinfo', 'Polling failed for report cache #' . $row['id'], ['exception' => $e->getMessage()]);
        $counts['errored']++;
    }
}

$summary = sprintf(
    "[%s] Polled %d, %d finished, %d errored.\n",
    date('Y-m-d H:i:s'),
    $counts['polled'],
    $counts['finished'],
    $counts['errored']
);
\App\Core\JobHeartbeat::ping('poll_creditinfo_reports', $summary, 15);
echo $summary;
