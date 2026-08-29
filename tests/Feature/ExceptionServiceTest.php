<?php

namespace Tests\Feature;

use App\Core\Correlation;
use App\Core\Database;
use App\Models\ExceptionRecord;
use App\Services\ExceptionService;
use PHPUnit\Framework\TestCase;

/** Runs against the local dev database -- see tests/bootstrap.php. */
class ExceptionServiceTest extends TestCase
{
    private array $createdIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdIds as $id) {
            $db->prepare('DELETE FROM exception_notes WHERE exception_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM exceptions WHERE id = ?')->execute([$id]);
        }
        $this->createdIds = [];
        Correlation::resetForTesting();
    }

    private function create(): int
    {
        $id = ExceptionService::create('phpunit_test_type', 'Test', 'Test', 'Medium', 'PHPUnit test exception');
        $this->createdIds[] = $id;
        return $id;
    }

    public function testCreationRecordsCoreFieldsAndDefaultsToOpen(): void
    {
        $id = $this->create();
        $row = (new ExceptionRecord())->find($id);

        $this->assertSame('OPEN', $row['status']);
        $this->assertSame('Medium', $row['severity']);
        $this->assertNotEmpty($row['exception_uuid']);
    }

    public function testCorrelationIdIsStampedFromTheCurrentRequest(): void
    {
        Correlation::set('REQ-TEST-EXCEPTIONCORR');
        $id = $this->create();

        $row = (new ExceptionRecord())->find($id);
        $this->assertSame('REQ-TEST-EXCEPTIONCORR', $row['correlation_id']);
    }

    public function testAssignmentSetsOwnerAndAdvancesStatus(): void
    {
        $id = $this->create();
        ExceptionService::assign($id, 1, 1);

        $row = (new ExceptionRecord())->find($id);
        $this->assertSame(1, (int) $row['owner_user_id']);
        $this->assertSame('ASSIGNED', $row['status']);
    }

    public function testResolutionCapturesResolutionAndRootCauseTogether(): void
    {
        $id = $this->create();
        ExceptionService::resolve($id, 'RESOLVED', 'Fixed the underlying issue', 'Config was wrong', 1);

        $row = (new ExceptionRecord())->find($id);
        $this->assertSame('RESOLVED', $row['status']);
        $this->assertSame('Fixed the underlying issue', $row['resolution']);
        $this->assertSame('Config was wrong', $row['root_cause']);
        $this->assertNotNull($row['resolved_at']);
    }

    public function testReopenIncrementsCountAndClearsTheResolution(): void
    {
        $id = $this->create();
        ExceptionService::resolve($id, 'RESOLVED', 'Fixed', null, 1);

        ExceptionService::reopen($id, 1, 'Issue recurred in production');

        $row = (new ExceptionRecord())->find($id);
        $this->assertSame('OPEN', $row['status']);
        $this->assertSame(1, (int) $row['reopened_count']);
        $this->assertNull($row['resolved_at']);
    }
}
