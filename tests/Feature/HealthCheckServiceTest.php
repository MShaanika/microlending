<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\HealthCheckService;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the local dev database -- see tests/bootstrap.php.
 *
 * checkScheduledJobs() aggregates across every row in
 * scheduled_job_heartbeats, including this dev environment's own real
 * cron heartbeats (which may themselves be stale between manual runs).
 * To test the aggregation logic in isolation, setUp() blanks out every
 * *other* job's expected_frequency_minutes for the duration of the test
 * (NULL is skipped by the check, same as a job that's never declared a
 * schedule) and tearDown() restores the original values -- this
 * doesn't touch last_run_at, so it can never mask a real problem for
 * longer than one test run.
 */
class HealthCheckServiceTest extends TestCase
{
    private array $heartbeatJobKeys = [];
    private array $savedFrequencies = [];
    private array $backupRunIds = [];

    protected function setUp(): void
    {
        $db = Database::connection();
        $this->savedFrequencies = $db->query('SELECT job_key, expected_frequency_minutes FROM scheduled_job_heartbeats')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $db->exec('UPDATE scheduled_job_heartbeats SET expected_frequency_minutes = NULL');
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->heartbeatJobKeys as $key) {
            $db->prepare('DELETE FROM scheduled_job_heartbeats WHERE job_key = ?')->execute([$key]);
        }
        $this->heartbeatJobKeys = [];

        foreach ($this->savedFrequencies as $jobKey => $freq) {
            $db->prepare('UPDATE scheduled_job_heartbeats SET expected_frequency_minutes = ? WHERE job_key = ?')->execute([$freq, $jobKey]);
        }
        $this->savedFrequencies = [];

        foreach ($this->backupRunIds as $id) {
            $db->prepare('DELETE FROM backup_runs WHERE id = ?')->execute([$id]);
        }
        $this->backupRunIds = [];
    }

    private function seedBackupRun(string $status, string $startedAtOffset, ?string $errorMessage = null): void
    {
        $db = Database::connection();
        $startedAt = date('Y-m-d H:i:s', strtotime($startedAtOffset));
        $stmt = $db->prepare(
            'INSERT INTO backup_runs (backup_type, status, started_at, completed_at, file_size_bytes, error_message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['database', $status, $startedAt, $startedAt, 1000000, $errorMessage]);
        $this->backupRunIds[] = (int) $db->lastInsertId();
    }

    public function testDatabaseCheckIsHealthyAgainstARealConnection(): void
    {
        $result = HealthCheckService::checkDatabase();
        $this->assertSame('HEALTHY', $result['status']);
        $this->assertNotNull($result['response_time_ms']);
    }

    public function testStorageCheckReturnsAKnownStatusWithoutThrowing(): void
    {
        $result = HealthCheckService::checkStorage();
        $this->assertContains($result['status'], ['HEALTHY', 'DEGRADED', 'UNHEALTHY', 'UNKNOWN']);
    }

    /** Part 36: with no backup_runs row at all, this must report UNKNOWN, never assume healthy from silence. */
    public function testBackupCheckIsUnknownWhenNoBackupHasEverRun(): void
    {
        $db = Database::connection();
        $existing = (int) $db->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn();
        if ($existing > 0) {
            $this->markTestSkipped('backup_runs already has data in this environment -- this test only proves the true-empty case.');
        }

        $result = HealthCheckService::checkBackup();
        $this->assertSame('UNKNOWN', $result['status']);
    }

    public function testBackupCheckIsHealthyForARecentSuccessfulBackup(): void
    {
        $this->seedBackupRun('SUCCESS', '-2 hours');
        $result = HealthCheckService::checkBackup();
        $this->assertSame('HEALTHY', $result['status']);
    }

    public function testBackupCheckIsDegradedWhenTheLastSuccessIsGettingStale(): void
    {
        $this->seedBackupRun('SUCCESS', '-60 hours');
        $result = HealthCheckService::checkBackup();
        $this->assertSame('DEGRADED', $result['status']);
    }

    public function testBackupCheckIsUnhealthyWhenTheLastSuccessIsVeryStale(): void
    {
        $this->seedBackupRun('SUCCESS', '-200 hours');
        $result = HealthCheckService::checkBackup();
        $this->assertSame('UNHEALTHY', $result['status']);
    }

    public function testBackupCheckIsUnhealthyWhenTheOnlyRunOnRecordFailed(): void
    {
        $this->seedBackupRun('FAILED', '-1 hour', 'disk full');
        $result = HealthCheckService::checkBackup();
        $this->assertSame('UNHEALTHY', $result['status']);
        $this->assertStringContainsString('disk full', $result['message']);
    }

    public function testScheduledJobsIsHealthyWhenEveryDeclaredJobIsWithinItsExpectedFrequency(): void
    {
        $this->seedHeartbeat('phpunit_fresh_job', '-1 minute', 10);

        $result = HealthCheckService::checkScheduledJobs();
        $this->assertSame('HEALTHY', $result['status']);
    }

    public function testScheduledJobsIsDegradedWhenAJobIsRunningLate(): void
    {
        // 2x-5x the expected frequency -- late, but not yet a missed run.
        $this->seedHeartbeat('phpunit_late_job', '-25 minutes', 10);

        $result = HealthCheckService::checkScheduledJobs();
        $this->assertSame('DEGRADED', $result['status']);
        $this->assertStringContainsString('phpunit_late_job', $result['message']);
    }

    public function testScheduledJobsIsUnhealthyWhenAJobHasClearlyMissedItsRuns(): void
    {
        // Beyond 5x the expected frequency -- a real missed-run signal.
        $this->seedHeartbeat('phpunit_missed_job', '-2 hours', 10);

        $result = HealthCheckService::checkScheduledJobs();
        $this->assertSame('UNHEALTHY', $result['status']);
        $this->assertStringContainsString('phpunit_missed_job', $result['message']);
    }

    private function seedHeartbeat(string $jobKey, string $lastRunOffset, int $expectedFrequencyMinutes): void
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO scheduled_job_heartbeats (job_key, last_run_at, last_summary, expected_frequency_minutes)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), expected_frequency_minutes = VALUES(expected_frequency_minutes)'
        )->execute([$jobKey, date('Y-m-d H:i:s', strtotime($lastRunOffset)), 'phpunit seed', $expectedFrequencyMinutes]);
        $this->heartbeatJobKeys[] = $jobKey;
    }
}
