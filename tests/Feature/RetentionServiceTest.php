<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\RetentionPolicy;
use App\Services\RetentionService;
use PHPUnit\Framework\TestCase;

/** Runs against the local dev database -- see tests/bootstrap.php. Uses the real seeded idempotency_keys_expiry policy since it's already on RetentionService's execution allowlist. */
class RetentionServiceTest extends TestCase
{
    private RetentionPolicy $policyModel;
    private array $createdKeys = [];
    private array $createdHolds = [];

    protected function setUp(): void
    {
        $this->policyModel = new RetentionPolicy();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdKeys as $key) {
            $db->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = ?')->execute([$key]);
        }
        $this->createdKeys = [];
        foreach ($this->createdHolds as $id) {
            $db->prepare('DELETE FROM legal_holds WHERE id = ?')->execute([$id]);
        }
        $this->createdHolds = [];
    }

    private function insertExpiredKey(): int
    {
        $key = 'phpunit-' . bin2hex(random_bytes(8));
        $db = Database::connection();
        $db->prepare(
            "INSERT INTO idempotency_keys (idempotency_key, operation_type, status, expires_at) VALUES (?, 'phpunit.test', 'COMPLETED', ?)"
        )->execute([$key, date('Y-m-d H:i:s', time() - 3600)]);
        $id = (int) $db->lastInsertId();
        $this->createdKeys[] = $key;
        return $id;
    }

    private function policy(): array
    {
        $id = (int) Database::connection()->query("SELECT id FROM retention_policies WHERE policy_key = 'idempotency_keys_expiry'")->fetchColumn();
        return $this->policyModel->find($id);
    }

    public function testEligibilityIdentifiesRowsPastTheirExpiry(): void
    {
        $this->insertExpiredKey();

        $preview = RetentionService::preview($this->policy());

        $this->assertGreaterThanOrEqual(1, $preview['eligible']);
    }

    public function testDryRunNeverDeletesAnything(): void
    {
        $id = $this->insertExpiredKey();

        RetentionService::preview($this->policy());

        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testLegalHoldPreventsDeletionOfAnOtherwiseEligibleRow(): void
    {
        $id = $this->insertExpiredKey();
        $holdId = $this->policyModel->placeHold('idempotency_keys', $id, 'PHPUnit test hold', 1);
        $this->createdHolds[] = $holdId;

        // idempotency_keys_expiry isn't legal_hold_supported by default --
        // this test forces it on in-memory only, to exercise the
        // hold-respecting code path without changing real policy config.
        $policy = $this->policy();
        $policy['legal_hold_supported'] = 1;

        $result = RetentionService::execute($policy, null);

        $this->assertSame(0, $result['deleted']);
        $this->assertGreaterThanOrEqual(1, $result['held']);

        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'A row under legal hold must survive a real execute() run.');
    }

    public function testSafeDeletionRemovesOnlyEligibleUnheldRowsAndRecordsTheRun(): void
    {
        $expiredId = $this->insertExpiredKey();
        $policy = $this->policy();

        $result = RetentionService::execute($policy, null);

        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM idempotency_keys WHERE id = ?');
        $stmt->execute([$expiredId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
        $this->assertGreaterThanOrEqual(1, $result['deleted']);

        $runStmt = Database::connection()->prepare('SELECT deleted_count FROM retention_runs WHERE policy_id = ? ORDER BY id DESC LIMIT 1');
        $runStmt->execute([$policy['id']]);
        $this->assertSame($result['deleted'], (int) $runStmt->fetchColumn());
    }

    public function testExecuteRefusesATableNotOnTheAllowlist(): void
    {
        $this->expectException(\RuntimeException::class);
        RetentionService::execute([
            'id' => 999999,
            'policy_name' => 'Not Allowed',
            'resource_table' => 'users', // never allowed -- a real, sensitive table
            'date_column' => 'created_at',
            'comparison_mode' => 'AGE_FROM_DATE_COLUMN',
            'retention_days' => 9999,
            'legal_hold_supported' => 0,
        ], null);
    }
}
