<?php

/**
 * Runs a full database backup via BackupService and prunes any backup
 * older than system_settings.backup_retention_days. Meant to run
 * once a day, e.g.:
 *
 *   0 1 * * * /usr/bin/php /path/to/bin/run_backup.php >> storage/logs/backup.log 2>&1
 *
 * Safe to run more than once on the same day -- each run is an
 * independent backup_runs row; retention pruning is idempotent (only
 * ever deletes rows already past their own retention_expires_at).
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Services\BackupService;

$result = BackupService::run('scheduled', null);

$summary = $result['success']
    ? sprintf("[%s] Backup succeeded: %s\n", date('Y-m-d H:i:s'), $result['file'])
    : sprintf("[%s] Backup FAILED: %s\n", date('Y-m-d H:i:s'), $result['error']);

\App\Core\JobHeartbeat::ping('run_backup', $summary, 1440);
echo $summary;
