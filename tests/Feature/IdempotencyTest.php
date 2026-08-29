<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Idempotency;
use App\Core\IdempotencyBusyException;
use App\Core\IdempotencyReplayException;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the local dev database (see tests/bootstrap.php) -- this
 * app has no separate test database. Every test cleans up the exact
 * idempotency_keys row it created so repeated runs stay independent.
 */
class IdempotencyTest extends TestCase
{
    private string $key;
    private string $operation = 'phpunit.idempotency_test';

    protected function setUp(): void
    {
        $this->key = 'phpunit-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = ? AND operation_type = ?')
            ->execute([$this->key, $this->operation]);
    }

    public function testBeginLetsAFirstRequestThrough(): void
    {
        // No exception -- this is the whole contract for a brand-new key.
        Idempotency::begin($this->key, $this->operation, null);
        $this->addToAssertionCount(1);
    }

    public function testCompleteThenBeginAgainReplaysTheStoredResponse(): void
    {
        Idempotency::begin($this->key, $this->operation, null);
        Idempotency::complete($this->key, $this->operation, 'REDIRECT', ['flash_message' => 'Done.']);

        try {
            Idempotency::begin($this->key, $this->operation, null);
            $this->fail('Expected IdempotencyReplayException for a completed operation.');
        } catch (IdempotencyReplayException $e) {
            $this->assertSame('REDIRECT', $e->responseType);
            $this->assertSame(['flash_message' => 'Done.'], $e->payload);
        }
    }

    public function testConcurrentPendingRequestIsBusyNotReplayed(): void
    {
        // Simulates a second, genuinely concurrent request for the same key
        // while the first is still mid-flight (status still PENDING) --
        // must be distinguished from a benign replay of a COMPLETED one.
        Idempotency::begin($this->key, $this->operation, null);

        $this->expectException(IdempotencyBusyException::class);
        Idempotency::begin($this->key, $this->operation, null);
    }

    public function testFailDeletesThePendingRowSoARetryCanProceed(): void
    {
        Idempotency::begin($this->key, $this->operation, null);
        Idempotency::fail($this->key, $this->operation);

        // No exception -- fail() cleared the row, so this is a fresh begin().
        Idempotency::begin($this->key, $this->operation, null);
        $this->addToAssertionCount(1);
    }
}
