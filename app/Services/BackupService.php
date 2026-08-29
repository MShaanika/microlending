<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\SystemSetting;

/**
 * Database backup automation (Part 7, Business Continuity/DR). Closes
 * the gap Phase 5's HealthCheckService::checkBackup() deliberately
 * left as UNKNOWN -- this is what actually produces the backups that
 * check now reads history from.
 *
 * Only the database is backed up here -- file-level/full-server
 * backups are a cPanel/hosting-level concern outside this app's
 * reach, same reasoning as Phase 1's Cloudflare origin-protection
 * steps being documented rather than automated. mysqldump is invoked
 * the same way every manual deploy backup this session already used.
 */
class BackupService
{
    public static function run(string $triggeredBy = 'scheduled', ?int $userId = null): array
    {
        $model = new BackupRun();
        $startedAt = date('Y-m-d H:i:s');
        $runId = $model->create([
            'backup_type' => 'database',
            'status' => 'RUNNING',
            'triggered_by' => $triggeredBy,
            'triggered_by_user' => $userId,
            'started_at' => $startedAt,
        ]);

        $start = microtime(true);

        try {
            $config = require ROOT_PATH . '/config/database.php';
            $dir = STORAGE_PATH . '/backups';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Backup directory $dir does not exist and could not be created.");
            }

            $file = $dir . '/db_backup_' . date('Ymd_His') . '.sql';
            // -p with no value at all (rather than an empty quoted
            // string) is the only form that reliably means "no
            // password" instead of triggering an interactive prompt
            // -- matters for local dev's empty-password root account;
            // production always has a real password so this branch
            // never applies there.
            $passwordFlag = $config['password'] !== '' ? '-p' . escapeshellarg($config['password']) : '';
            $cmd = sprintf(
                'mysqldump --routines --triggers --single-transaction -h%s -P%s -u%s %s %s > %s 2>&1',
                escapeshellarg($config['host'] ?? '127.0.0.1'),
                escapeshellarg((string) ($config['port'] ?? '3306')),
                escapeshellarg($config['username']),
                $passwordFlag,
                escapeshellarg($config['database']),
                escapeshellarg($file)
            );
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || !self::isValidDump($file)) {
                $error = !empty($output) ? implode("\n", $output) : 'mysqldump exited with code ' . $exitCode . ' or produced an invalid file.';
                throw new \RuntimeException($error);
            }

            $retentionDays = (int) ((new SystemSetting())->get('backup_retention_days', '30') ?? '30');
            $model->updateRun($runId, [
                'file_path' => $file,
                'file_size_bytes' => filesize($file),
                'status' => 'SUCCESS',
                'completed_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => (int) round(microtime(true) - $start),
                'retention_expires_at' => date('Y-m-d H:i:s', strtotime("+{$retentionDays} days")),
            ]);

            self::pruneExpired();

            return ['success' => true, 'run_id' => $runId, 'file' => $file];
        } catch (\Throwable $e) {
            $model->updateRun($runId, [
                'status' => 'FAILED',
                'completed_at' => date('Y-m-d H:i:s'),
                'duration_seconds' => (int) round(microtime(true) - $start),
                'error_message' => substr($e->getMessage(), 0, 2000),
            ]);
            return ['success' => false, 'run_id' => $runId, 'error' => $e->getMessage()];
        }
    }

    /** A cheap, honest integrity signal -- not a full restore test, just enough to catch a truncated/empty/wrong-content file before it's trusted as a real recovery point. */
    private static function isValidDump(string $file): bool
    {
        if (!is_file($file) || filesize($file) < 100) {
            return false;
        }
        $handle = fopen($file, 'r');
        if (!$handle) {
            return false;
        }
        $head = fread($handle, 4096);
        fclose($handle);
        return str_contains($head, 'MySQL dump') || str_contains($head, 'CREATE TABLE');
    }

    public static function pruneExpired(): void
    {
        $model = new BackupRun();
        foreach ($model->expired() as $run) {
            if (!empty($run['file_path']) && is_file($run['file_path'])) {
                @unlink($run['file_path']);
            }
            $model->delete((int) $run['id']);
        }
    }
}
