<?php

namespace Tests\Unit;

use App\Core\Correlation;
use PHPUnit\Framework\TestCase;

class CorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        Correlation::resetForTesting();
    }

    protected function tearDown(): void
    {
        // Correlation::$id is static/process-wide, not per-TestCase --
        // without this, testSetAdoptsAnExternallySuppliedId()'s fixed ID
        // would leak into whichever unrelated test (in this class or any
        // other) happens to run next in the same PHPUnit process.
        Correlation::resetForTesting();
    }

    public function testIdMatchesTheDocumentedFormat(): void
    {
        // REQ-YYYYMMDD-XXXXXXXX -- see the Enterprise Control Architecture's
        // Part 4 (Global Correlation IDs) example: REQ-20260829-7F93A21C.
        $this->assertMatchesRegularExpression('/^REQ-\d{8}-[0-9A-F]{8}$/', Correlation::id());
    }

    public function testIdIsStableWithinTheSameRequest(): void
    {
        $first = Correlation::id();
        $second = Correlation::id();
        $this->assertSame($first, $second);
    }

    public function testSetAdoptsAnExternallySuppliedId(): void
    {
        Correlation::set('REQ-19700101-DEADBEEF');
        $this->assertSame('REQ-19700101-DEADBEEF', Correlation::id());
    }
}
