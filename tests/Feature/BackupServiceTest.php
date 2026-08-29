<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\BackupService;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the local dev database -- see tests/bootstrap.php.
 *
 * BackupService::run() itself shells out to mysqldump -- environment-
 * dependent (PATH, credentials) in a way that doesn't belong in an
 * automated suite, so these tests exercise pruneExpired() directly
 * against seeded backup_runs rows instead of a real dump.
 */
class BackupServiceTest extends TestCase
{
    private array $createdIds = [];
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdIds as $id) {
            $db->prepare('DELETE FROM backup_runs WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
        foreach ($this->createdFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->createdFiles = [];
    }

    private function seedRun(string $status, ?string $retentionExpiresAt, bool $withFile = true): array
    {
        $db = Database::connection();
        $file = null;
        if ($withFile) {
            $file = STORAGE_PATH . '/backups/phpunit_test_' . uniqid() . '.sql';
            file_put_contents($file, '-- MySQL dump test file');
            $this->createdFiles[] = $file;
        }

        $stmt = $db->prepare(
            'INSERT INTO backup_runs (backup_type, status, started_at, completed_at, file_path, file_size_bytes, retention_expires_at)
             VALUES (?, ?, NOW(), NOW(), ?, ?, ?)'
        );
        $stmt->execute(['database', $status, $file, $file ? filesize($file) : null, $retentionExpiresAt]);
        $id = (int) $db->lastInsertId();
        $this->createdIds[] = $id;

        return ['id' => $id, 'file' => $file];
    }

    public function testPruneExpiredDeletesTheRowAndItsFile(): void
    {
        $run = $this->seedRun('SUCCESS', date('Y-m-d H:i:s', strtotime('-1 day')));

        BackupService::pruneExpired();

        $this->assertFileDoesNotExist($run['file']);
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM backup_runs WHERE id = ?');
        $stmt->execute([$run['id']]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testPruneExpiredLeavesNonExpiredBackupsAlone(): void
    {
        $run = $this->seedRun('SUCCESS', date('Y-m-d H:i:s', strtotime('+10 days')));

        BackupService::pruneExpired();

        $this->assertFileExists($run['file']);
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM backup_runs WHERE id = ?');
        $stmt->execute([$run['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testPruneExpiredIgnoresFailedRunsRegardlessOfRetentionDate(): void
    {
        // A FAILED run has no meaningful retention window -- pruneExpired()
        // only ever acts on SUCCESS rows.
        $run = $this->seedRun('FAILED', date('Y-m-d H:i:s', strtotime('-1 day')));

        BackupService::pruneExpired();

        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM backup_runs WHERE id = ?');
        $stmt->execute([$run['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
