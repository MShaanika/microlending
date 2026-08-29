<?php

namespace App\Core;

/**
 * One-line addition to the end of every bin/*.php cron script -- what
 * HealthCheckService's "Scheduled Jobs" check and Part 38's "missed
 * jobs" detection actually read. Never throws: a heartbeat write
 * failing must not turn a successful cron run into a failed one.
 */
class JobHeartbeat
{
    public static function ping(string $jobKey, string $summary, ?int $expectedFrequencyMinutes = null): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare(
                "INSERT INTO scheduled_job_heartbeats (job_key, last_run_at, last_summary, expected_frequency_minutes)
                 VALUES (?, NOW(), ?, ?)
                 ON DUPLICATE KEY UPDATE last_run_at = NOW(), last_summary = VALUES(last_summary), expected_frequency_minutes = VALUES(expected_frequency_minutes)"
            );
            $stmt->execute([$jobKey, $summary, $expectedFrequencyMinutes]);
        } catch (\Throwable $e) {
            error_log("JobHeartbeat::ping('$jobKey') failed: " . $e->getMessage());
        }
    }
}
