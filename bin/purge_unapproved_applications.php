<?php

/**
 * Daily clean-up of applications that were never approved (frees disk space).
 *
 *   30 2 * * * /usr/bin/php /path/to/bin/purge_unapproved_applications.php >> storage/logs/application_purge.log 2>&1
 *
 * SAFE BY DEFAULT. While system_settings.application_purge_enabled is not '1'
 * (or when run with --dry-run) it ONLY reports what it would do: nothing is
 * deleted and no warning is sent. When enabled it
 *   1. clears stale warnings (application got approved/extended meanwhile),
 *   2. warns staff by e-mail about applications that will be deleted soon
 *      (and about old ones blocked by an unanswered Loan Question),
 *   3. deletes only applications that were warned at least
 *      application_purge_warning_days ago, at most application_purge_max_per_run
 *      per run, and only if a successful backup exists from the last 24 hours.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\BackupRun;
use App\Services\ApplicationPurgeService;

$dryRun = in_array('--dry-run', $argv ?? [], true) || !ApplicationPurgeService::enabled();
$svc = new ApplicationPurgeService();
$days = ApplicationPurgeService::retentionDays();
$out = [];

$eligible = $svc->eligible($days);
$blocked = $svc->blockedByLoanQuestion($days);
$out[] = sprintf('retention %d days, warning %d days, mode %s', $days, ApplicationPurgeService::warningDays(), $dryRun ? 'DRY-RUN (nothing is deleted or sent)' : 'LIVE');
$out[] = sprintf('eligible now: %d, blocked by an unanswered Loan Question: %d', count($eligible), count($blocked));

if ($dryRun) {
    foreach (array_slice($eligible, 0, 25) as $r) {
        $plan = $svc->plan((int) $r['id']);
        $out[] = sprintf('  would delete %s (%s, last change %s): %s, %d document(s), %d file(s), %.2f MB',
            $r['application_no'], $r['status'], substr((string) $r['anchor_at'], 0, 10), $plan['mode'], $plan['documents'], $plan['files'], $plan['bytes'] / 1048576);
    }
    $out[] = sprintf('%d application(s) would receive a warning first.', count($svc->dueSoon()));
} else {
    $cleared = $svc->clearStaleWarnings();
    $w = $svc->warn();
    $out[] = sprintf('stale warnings cleared: %d; warned %d application(s), %d e-mail(s) queued', $cleared, $w['warned'], $w['emails']);

    $backupAge = (new BackupRun())->ageHoursOfLastSuccess();
    if ($backupAge === null || $backupAge > 24) {
        $out[] = 'NO deletion today: no successful backup in the last 24 hours (last: ' . ($backupAge === null ? 'never' : $backupAge . ' h ago') . ').';
    } else {
        $max = max(1, (int) ApplicationPurgeService::setting('application_purge_max_per_run', '50'));
        $deleted = 0;
        $tomb = 0;
        $bytes = 0;
        $failed = 0;
        foreach ($svc->eligible($days, true, $max) as $r) {
            $res = $svc->purge((int) $r['id']);
            if ($res['ok']) {
                $res['mode'] === 'tombstone' ? $tomb++ : $deleted++;
                $bytes += $res['bytes'];
            } else {
                $failed++;
            }
        }
        $out[] = sprintf('deleted %d, wiped-but-kept %d (Creditinfo record), skipped/failed %d, %.2f MB of files moved to quarantine', $deleted, $tomb, $failed, $bytes / 1048576);
    }
    $q = $svc->sweepQuarantine();
    $out[] = sprintf('quarantine: %d old folder(s) really deleted, %.2f MB freed', $q['folders'], $q['bytes'] / 1048576);
}

$summary = '[' . date('Y-m-d H:i:s') . '] ' . implode("\n  ", $out) . "\n";
\App\Core\JobHeartbeat::ping('purge_unapproved_applications', $out[1] . ($dryRun ? ' (dry-run)' : ''), 1440);
echo $summary;
