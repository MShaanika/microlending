<?php

namespace App\Models;

use App\Core\Model;

class HealthCheckResult extends Model
{
    public function record(string $checkKey, string $targetName, string $status, ?int $responseTimeMs, ?string $message): int
    {
        return $this->insert('health_check_results', [
            'check_key' => $checkKey,
            'target_name' => $targetName,
            'status' => $status,
            'response_time_ms' => $responseTimeMs,
            'message' => $message,
        ]);
    }

    /** Latest row per check_key -- the append-only table's "current state" view. */
    public function latestByCheck(): array
    {
        return $this->all(
            'SELECT r.* FROM health_check_results r
             INNER JOIN (
                 SELECT check_key, MAX(id) AS max_id FROM health_check_results GROUP BY check_key
             ) latest ON latest.check_key = r.check_key AND latest.max_id = r.id
             ORDER BY r.target_name'
        );
    }

    public function history(string $checkKey, int $limit = 50): array
    {
        return $this->all(
            'SELECT * FROM health_check_results WHERE check_key = ? ORDER BY checked_at DESC LIMIT ' . max(1, $limit),
            [$checkKey]
        );
    }

    /**
     * age_minutes is computed by MySQL itself (TIMESTAMPDIFF against
     * MySQL's own NOW()), not PHP's time()/strtotime() against a
     * stored value -- production's MySQL server clock and PHP's
     * configured timezone (Africa/Windhoek) don't agree, so mixing
     * the two silently produces a multi-hour error. Keeping the whole
     * comparison on MySQL's own clock sidesteps that regardless of
     * either side's timezone configuration.
     */
    public function heartbeats(): array
    {
        return $this->all('SELECT *, TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) AS age_minutes FROM scheduled_job_heartbeats ORDER BY job_key');
    }
}
