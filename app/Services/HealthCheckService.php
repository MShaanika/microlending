<?php

namespace App\Services;

use App\Core\Database;
use App\Models\CollexiaSetting;
use App\Models\HealthCheckResult;

/**
 * Automated health checks (Part 35-38). Every check here is a real,
 * honest probe of something that can actually be verified right now --
 * Part 36 is explicit that nothing gets marked healthy just because no
 * error was reported. Backup is the deliberate exception: no in-app
 * backup automation exists yet (that's a later phase), so it is always
 * reported UNKNOWN rather than faked green.
 */
class HealthCheckService
{
    /** Missed-heartbeat grace factor before a scheduled job counts as DEGRADED/UNHEALTHY -- avoids flapping on a job that's merely a few minutes late. */
    private const DEGRADED_MULTIPLIER = 2;
    private const UNHEALTHY_MULTIPLIER = 5;

    public static function runAll(): array
    {
        $checks = [
            self::checkDatabase(),
            self::checkStorage(),
            self::checkScheduledJobs(),
            self::checkNotifications(),
            self::checkSecurityMonitor(),
            self::checkCollexia(),
            self::checkBackup(),
        ];

        $model = new HealthCheckResult();
        foreach ($checks as $check) {
            $model->record($check['check_key'], $check['target_name'], $check['status'], $check['response_time_ms'], $check['message']);
        }

        return $checks;
    }

    public static function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            $db = Database::connection();
            $db->query('SELECT 1')->fetchColumn();
            $ms = (int) round((microtime(true) - $start) * 1000);
            $status = $ms > 1000 ? 'DEGRADED' : 'HEALTHY';
            return self::result('database', 'MySQL Connection', $status, $ms, $ms > 1000 ? "Slow response: {$ms}ms" : 'Connected OK.');
        } catch (\Throwable $e) {
            return self::result('database', 'MySQL Connection', 'UNHEALTHY', null, 'Connection failed: ' . $e->getMessage());
        }
    }

    public static function checkStorage(): array
    {
        try {
            $path = STORAGE_PATH;
            if (!is_dir($path) || !is_writable($path)) {
                return self::result('storage', 'Storage Directory', 'UNHEALTHY', null, "$path is missing or not writable.");
            }
            $free = @disk_free_space($path);
            if ($free === false) {
                return self::result('storage', 'Storage Directory', 'DEGRADED', null, 'Writable, but free space could not be determined.');
            }
            $freeGb = round($free / 1073741824, 2);
            $status = $freeGb < 1 ? 'UNHEALTHY' : ($freeGb < 5 ? 'DEGRADED' : 'HEALTHY');
            return self::result('storage', 'Storage Directory', $status, null, "{$freeGb} GB free.");
        } catch (\Throwable $e) {
            return self::result('storage', 'Storage Directory', 'UNKNOWN', null, $e->getMessage());
        }
    }

    /** One aggregate check across all jobs in scheduled_job_heartbeats -- per-job detail is on the Health dashboard's own heartbeat table, not duplicated into separate check rows. */
    public static function checkScheduledJobs(): array
    {
        try {
            $jobs = (new HealthCheckResult())->heartbeats();
            if (empty($jobs)) {
                return self::result('scheduled_jobs', 'Scheduled Jobs', 'UNKNOWN', null, 'No jobs have reported a heartbeat yet.');
            }

            $stale = [];
            $veryStale = [];
            $now = time();
            foreach ($jobs as $job) {
                $freq = (int) ($job['expected_frequency_minutes'] ?? 0);
                if ($freq <= 0) {
                    continue;
                }
                $ageMinutes = ($now - strtotime($job['last_run_at'])) / 60;
                if ($ageMinutes > $freq * self::UNHEALTHY_MULTIPLIER) {
                    $veryStale[] = $job['job_key'];
                } elseif ($ageMinutes > $freq * self::DEGRADED_MULTIPLIER) {
                    $stale[] = $job['job_key'];
                }
            }

            if (!empty($veryStale)) {
                return self::result('scheduled_jobs', 'Scheduled Jobs', 'UNHEALTHY', null, 'Missed runs: ' . implode(', ', $veryStale));
            }
            if (!empty($stale)) {
                return self::result('scheduled_jobs', 'Scheduled Jobs', 'DEGRADED', null, 'Running late: ' . implode(', ', $stale));
            }
            return self::result('scheduled_jobs', 'Scheduled Jobs', 'HEALTHY', null, count($jobs) . ' job(s) reporting on schedule.');
        } catch (\Throwable $e) {
            return self::result('scheduled_jobs', 'Scheduled Jobs', 'UNKNOWN', null, $e->getMessage());
        }
    }

    public static function checkNotifications(): array
    {
        try {
            $db = Database::connection();
            $row = $db->query(
                "SELECT
                    SUM(CASE WHEN status = 'Sent' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) AS failed
                 FROM notification_queue WHERE created_at >= NOW() - INTERVAL 24 HOUR"
            )->fetch();

            $sent = (int) ($row['sent'] ?? 0);
            $failed = (int) ($row['failed'] ?? 0);
            $total = $sent + $failed;
            if ($total === 0) {
                return self::result('notifications', 'Notification Delivery', 'HEALTHY', null, 'No notifications sent in the last 24h.');
            }
            $failRate = round(($failed / $total) * 100, 1);
            $status = $failRate >= 25 ? 'UNHEALTHY' : ($failRate >= 5 ? 'DEGRADED' : 'HEALTHY');
            return self::result('notifications', 'Notification Delivery', $status, null, "{$failRate}% failed ({$failed}/{$total}) in the last 24h.");
        } catch (\Throwable $e) {
            return self::result('notifications', 'Notification Delivery', 'UNKNOWN', null, $e->getMessage());
        }
    }

    public static function checkSecurityMonitor(): array
    {
        try {
            $db = Database::connection();
            $db->query('SELECT COUNT(*) FROM security_events')->fetchColumn();
            return self::result('security_monitor', 'Security Monitoring Pipeline', 'HEALTHY', null, 'Security event storage is reachable.');
        } catch (\Throwable $e) {
            return self::result('security_monitor', 'Security Monitoring Pipeline', 'UNHEALTHY', null, $e->getMessage());
        }
    }

    public static function checkCollexia(): array
    {
        try {
            $settings = new CollexiaSetting();
            if (!$settings->isEnabled() || !$settings->isConfigured()) {
                return self::result('collexia_api', 'Collexia API Integration', 'UNKNOWN', null, 'Integration is disabled or not configured.');
            }

            $db = Database::connection();
            $stmt = $db->prepare("SELECT last_run_at FROM scheduled_job_heartbeats WHERE job_key = 'download_collexia_payments'");
            $stmt->execute();
            $lastRun = $stmt->fetchColumn();
            if (!$lastRun) {
                return self::result('collexia_api', 'Collexia API Integration', 'UNKNOWN', null, 'Enabled, but no sync has run yet.');
            }

            $ageHours = (time() - strtotime($lastRun)) / 3600;
            $status = $ageHours > 24 ? 'UNHEALTHY' : ($ageHours > 8 ? 'DEGRADED' : 'HEALTHY');
            return self::result('collexia_api', 'Collexia API Integration', $status, null, 'Last sync ' . round($ageHours, 1) . 'h ago.');
        } catch (\Throwable $e) {
            return self::result('collexia_api', 'Collexia API Integration', 'UNKNOWN', null, $e->getMessage());
        }
    }

    /** No backup automation exists in-app yet -- honestly UNKNOWN rather than assuming healthy from silence (Part 36). */
    public static function checkBackup(): array
    {
        return self::result('backup', 'Backup Status', 'UNKNOWN', null, 'No in-app backup automation exists yet -- verify backups through hosting/ops tooling directly.');
    }

    private static function result(string $checkKey, string $targetName, string $status, ?int $responseTimeMs, string $message): array
    {
        return [
            'check_key' => $checkKey,
            'target_name' => $targetName,
            'status' => $status,
            'response_time_ms' => $responseTimeMs,
            'message' => $message,
        ];
    }
}
