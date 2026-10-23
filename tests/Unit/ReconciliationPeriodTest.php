<?php

namespace Tests\Unit;

use App\Support\ReconciliationPeriod;
use PHPUnit\Framework\TestCase;

/** The Bank Reconciliation screen works on a From/To period; a bare old-style as_of_date still has to work. */
class ReconciliationPeriodTest extends TestCase
{
    public function testFromAndToAreUsedAsGiven(): void
    {
        $p = ReconciliationPeriod::resolve(['from_date' => '2026-10-01', 'to_date' => '2026-10-31'], '2026-11-05');
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31', 'error' => null], $p);
    }

    public function testDefaultsToTheCurrentMonthSoFar(): void
    {
        $p = ReconciliationPeriod::resolve([], '2026-10-23');
        $this->assertSame('2026-10-01', $p['from']);
        $this->assertSame('2026-10-23', $p['to']);
    }

    public function testAnOldAsOfDateLinkBecomesTheToDateOfThatMonth(): void
    {
        $p = ReconciliationPeriod::resolve(['as_of_date' => '2026-08-31'], '2026-10-23');
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31', 'error' => null], $p);
    }

    public function testToDateWinsOverAnOldAsOfDate(): void
    {
        $p = ReconciliationPeriod::resolve(['to_date' => '2026-09-30', 'as_of_date' => '2026-08-31'], '2026-10-23');
        $this->assertSame('2026-09-30', $p['to']);
    }

    public function testFromAfterToIsResetAndExplained(): void
    {
        $p = ReconciliationPeriod::resolve(['from_date' => '2026-11-10', 'to_date' => '2026-10-31'], '2026-11-12');
        $this->assertSame('2026-10-01', $p['from']);
        $this->assertSame('2026-10-31', $p['to']);
        $this->assertNotNull($p['error']);
    }

    /** @dataProvider badDates */
    public function testGarbageDatesFallBackToDefaults(string $bad): void
    {
        $p = ReconciliationPeriod::resolve(['from_date' => $bad, 'to_date' => $bad], '2026-10-23');
        $this->assertSame('2026-10-01', $p['from']);
        $this->assertSame('2026-10-23', $p['to']);
    }

    public static function badDates(): array
    {
        return [['not a date'], ['2026-13-40'], ['2026-02-30'], ['23/10/2026'], ["2026-10-01' OR 1=1"], ['']];
    }

    public function testASingleDayPeriodIsAllowed(): void
    {
        $p = ReconciliationPeriod::resolve(['from_date' => '2026-10-15', 'to_date' => '2026-10-15'], '2026-10-23');
        $this->assertSame('2026-10-15', $p['from']);
        $this->assertNull($p['error']);
    }

    public function testQueryCarriesThePeriodAndSwitchesThroughARedirect(): void
    {
        $q = ReconciliationPeriod::query(['from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'include_earlier' => '1', 'unmatched_only' => '1'], '2026-11-05');
        $this->assertSame('&from_date=2026-10-01&to_date=2026-10-31&include_earlier=1&unmatched_only=1', $q);
        $this->assertSame('&from_date=2026-11-01&to_date=2026-11-05', ReconciliationPeriod::query([], '2026-11-05'));
    }
}
