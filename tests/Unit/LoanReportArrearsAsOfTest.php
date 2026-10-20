<?php

namespace Tests\Unit;

use App\Services\LoanReportService;
use PHPUnit\Framework\TestCase;

class LoanReportArrearsAsOfTest extends TestCase
{
    public function testAFuturePeriodEndIsCappedAtToday(): void
    {
        $this->assertSame('2026-10-18', LoanReportService::arrearsAsOf('2026-10-31', '2026-10-18'));
    }

    public function testAPastOrCurrentPeriodEndIsUsedAsIs(): void
    {
        $this->assertSame('2026-09-30', LoanReportService::arrearsAsOf('2026-09-30', '2026-10-18'));
        $this->assertSame('2026-10-18', LoanReportService::arrearsAsOf('2026-10-18', '2026-10-18'));
    }
}
