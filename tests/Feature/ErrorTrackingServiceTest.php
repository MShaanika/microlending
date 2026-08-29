<?php

namespace Tests\Feature;

use App\Core\Correlation;
use App\Core\Database;
use App\Core\ErrorTrackingService;
use App\Models\SystemError;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the local dev database -- see tests/bootstrap.php.
 *
 * Fingerprinting is class+file+line, not message (Part 5-6) -- every
 * test below throws from its own distinct source line so captures
 * across different tests never collide with each other. Where a test
 * specifically needs two captures to collide (the dedup test), both
 * throws are made from a single shared closure so they share exactly
 * one line, unique to that test.
 */
class ErrorTrackingServiceTest extends TestCase
{
    private array $createdIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdIds as $id) {
            $row = (new SystemError())->find($id);
            $exceptionId = $row['exception_id'] ?? null;
            $db->prepare('DELETE FROM system_errors WHERE id = ?')->execute([$id]);
            if ($exceptionId) {
                $db->prepare('DELETE FROM exceptions WHERE id = ?')->execute([$exceptionId]);
            }
        }
        $this->createdIds = [];
        Correlation::resetForTesting();
    }

    public function testCaptureRecordsCoreFieldsAndDefaultsToNew(): void
    {
        $result = ErrorTrackingService::capture(new \RuntimeException('unique-' . uniqid()));
        $row = $this->trackAndFind($result['error_uuid']);

        $this->assertTrue($result['is_new']);
        $this->assertSame('NEW', $row['status']);
        $this->assertSame('RuntimeException', $row['exception_class']);
        $this->assertSame(1, (int) $row['occurrence_count']);
    }

    public function testCorrelationIdIsStampedFromTheCurrentRequest(): void
    {
        Correlation::set('REQ-TEST-ERRORCORR');
        $result = ErrorTrackingService::capture(new \RuntimeException('unique-' . uniqid()));

        $row = $this->trackAndFind($result['error_uuid']);
        $this->assertSame('REQ-TEST-ERRORCORR', $row['correlation_id']);
    }

    public function testRepeatedIdenticalErrorDedupesByFingerprintInsteadOfDuplicating(): void
    {
        $make = fn () => new \RuntimeException('dedup-test-' . uniqid());

        $first = ErrorTrackingService::capture($make());
        $second = ErrorTrackingService::capture($make());

        $this->assertTrue($first['is_new']);
        $this->assertFalse($second['is_new']);
        $this->assertSame($first['error_uuid'], $second['error_uuid']);

        $row = $this->trackAndFind($first['error_uuid']);
        $this->assertSame(2, (int) $row['occurrence_count']);
    }

    public function testCriticalSeverityEscalatesToAnException(): void
    {
        $result = ErrorTrackingService::capture(new \RuntimeException('critical-' . uniqid()), 'Critical');
        $row = $this->trackAndFind($result['error_uuid']);

        $this->assertNotNull($row['exception_id']);
    }

    public function testNonCriticalSeverityDoesNotCreateAnException(): void
    {
        $result = ErrorTrackingService::capture(new \RuntimeException('high-' . uniqid()), 'High');
        $row = $this->trackAndFind($result['error_uuid']);

        $this->assertNull($row['exception_id']);
    }

    private function trackAndFind(?string $uuid): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM system_errors WHERE error_uuid = ?');
        $stmt->execute([$uuid]);
        $id = (int) $stmt->fetchColumn();
        $this->createdIds[] = $id;
        return (new SystemError())->find($id);
    }
}
