<?php

/**
 * Automatic CPL submission to Creditinfo, built from the Loan Questions answers (see App\Services\CplAutoSubmissionService for every rule).
 *
 *   php bin/cpl_auto_submit.php --daily   [--dry-run] [--date=YYYY-MM-DD]   new registrations (R) and closures (C) since the last daily file
 *   php bin/cpl_auto_submit.php --monthly [--dry-run] [--date=YYYY-MM-DD]   every completed month end not yet sent, oldest first (each from the Nth working day of the next month)
 *
 * The options are checked strictly BEFORE anything is loaded or touched (App\Support\CplAutoSubmitArgs): an unknown, repeated or positional argument, or
 * not exactly one of --daily / --monthly, prints the usage and exits with 1.
 *
 * CRONTAB (server time; NOT installed by the code, add these two lines yourself with `crontab -e` when you are ready):
 *
 *   30 6 * * *  cd /path/to/app && php bin/cpl_auto_submit.php --daily   >> storage/logs/cpl_auto_daily.log 2>&1
 *   0  7 * * *  cd /path/to/app && php bin/cpl_auto_submit.php --monthly >> storage/logs/cpl_auto_monthly.log 2>&1
 *
 * The monthly line runs every day on purpose: it does nothing until the send day of the month has arrived, then sends the months that are due (oldest
 * first) once each, and does nothing again until the next one (a missed or blocked day is simply retried the next morning).
 *
 * NOTHING is sent unless the switch "Automatic submissions" on the CPL dashboard is ON (setting cpl_auto_submit_enabled = 1, which also records the standing
 * approval), the submission environment in CPL Settings is Production, the SFTP connection is ready and the file passes every check. In every other case
 * the file is stored as a draft on the CPL dashboard, nothing is uploaded and the staff are e-mailed (once a day per reason).
 *
 * Each real run reports to the job heartbeat (cpl_auto_submit_daily / cpl_auto_submit_monthly); the dashboard and an e-mail warn when the switch is ON and
 * a heartbeat is missing or older than 30 hours.
 *
 * --dry-run prints what would be sent (or why it would not) and writes NOTHING: no batch, no setting, no e-mail, no upload.
 * --date acts as "today" for catching up or testing; it can never be a future date.
 *
 * Exit code: 0 = ran (even when it correctly sent nothing), 1 = bad options or the run itself stopped with an error.
 */

use App\Support\CplAutoSubmitArgs;

// 1. arguments first: nothing is loaded, connected or written before they are known to be exactly what this script accepts
require_once __DIR__ . '/../app/Support/CplAutoSubmitArgs.php';
try {
    $args = CplAutoSubmitArgs::parse($argv ?? []);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . CplAutoSubmitArgs::USAGE);
    exit(1);
}

require __DIR__ . '/../bootstrap/app.php';

$kind = $args['kind'];
$dryRun = $args['dry_run'];
$date = $args['date'];

$service = new \App\Services\CplAutoSubmissionService();
$result = $kind === 'daily' ? $service->runDaily($dryRun, $date) : $service->runMonthly($dryRun, $date);

$lines = ['[' . date('Y-m-d H:i:s') . '] CPL automatic ' . $kind . ($dryRun ? ' (DRY RUN, nothing written)' : '') . ': ' . $result['outcome'] . ' - ' . $result['message']];
if (isset($result['file'])) {
    $lines[] = '  file: ' . $result['file'] . ' (' . ($result['records'] ?? 0) . ' record(s), ' . ($result['exceptions'] ?? 0) . ' loan(s) held back)';
}
foreach ($result['preview'] ?? [] as $p) {
    $lines[] = '  would include: ' . $p;
}
foreach ($result['exception_list'] ?? [] as $x) {
    $lines[] = '  held back: ' . $x;
}
$summary = implode("\n", $lines) . "\n";

if (!$dryRun) {
    // the heartbeat carries the outcome only (no names, no loan numbers): it is shown on the health pages
    \App\Core\JobHeartbeat::ping('cpl_auto_submit_' . $kind, '[' . date('Y-m-d H:i:s') . '] CPL automatic ' . $kind . ': ' . $result['outcome'], 1440);
    try {
        $service->watchCron(); // e-mails the staff (once a day) when the switch is ON and a job has not reported for 30+ hours
    } catch (\Throwable $e) {
        error_log('cpl_auto_submit: cron watch failed: ' . get_class($e));
    }
}
echo $summary;
exit(in_array($result['outcome'], ['error', 'bad_date'], true) ? 1 : 0);
