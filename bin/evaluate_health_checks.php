<?php

/**
 * Runs every HealthCheckService probe (Database, Storage, Scheduled Jobs,
 * Notification Delivery, Security Monitor, Collexia API, Backup) and
 * records the results. Meant to run frequently, e.g.:
 *
 *   (every 5-10 min) /usr/bin/php /path/to/bin/evaluate_health_checks.php >> storage/logs/health_checks.log 2>&1
 *
 * Part 38: a check that transitions INTO unhealthy (i.e. it was not
 * already unhealthy on the previous run) auto-creates an Exception via
 * ExceptionService -- deliberately transition-based, not "every unhealthy
 * reading," so a sustained outage creates one exception to work, not one
 * per sweep.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Models\HealthCheckResult;
use App\Services\ExceptionService;
use App\Services\HealthCheckService;

$model = new HealthCheckResult();

$previousStatus = [];
foreach ($model->latestByCheck() as $row) {
    $previousStatus[$row['check_key']] = $row['status'];
}

$results = HealthCheckService::runAll();

$newlyUnhealthy = 0;
foreach ($results as $result) {
    $wasUnhealthy = ($previousStatus[$result['check_key']] ?? null) === 'UNHEALTHY';
    if ($result['status'] === 'UNHEALTHY' && !$wasUnhealthy) {
        ExceptionService::create(
            'health_check_failure',
            'Technical',
            'Platform',
            'High',
            "Health check '{$result['target_name']}' became UNHEALTHY: {$result['message']}",
            'health_check',
            null
        );
        $newlyUnhealthy++;
    }
}

$counts = array_count_values(array_column($results, 'status'));
$summary = sprintf(
    "[%s] Health sweep: %d check(s) -- %d healthy, %d degraded, %d unhealthy, %d unknown. %d new exception(s) raised.\n",
    date('Y-m-d H:i:s'),
    count($results),
    $counts['HEALTHY'] ?? 0,
    $counts['DEGRADED'] ?? 0,
    $counts['UNHEALTHY'] ?? 0,
    $counts['UNKNOWN'] ?? 0,
    $newlyUnhealthy
);
\App\Core\JobHeartbeat::ping('evaluate_health_checks', $summary, 10);
echo $summary;

// The automatic CPL submission is watched from here too: this job keeps running when the CPL jobs themselves do not. It e-mails the staff (once a day)
// when automatic submission is ON and a CPL job has not reported for more than 30 hours; it does nothing at all when the switch is OFF.
try {
    (new \App\Services\CplAutoSubmissionService())->watchCron();
} catch (\Throwable $e) {
    error_log('evaluate_health_checks: CPL cron watch failed: ' . get_class($e));
}
