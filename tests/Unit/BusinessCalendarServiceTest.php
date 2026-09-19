<?php

namespace Tests\Unit;

use App\Services\BusinessCalendarService;
use PHPUnit\Framework\TestCase;

/**
 * Pure logic only -- no database. isBusinessDay()/previousBusinessDay()/
 * nextBusinessDay() (the instance methods that read public_holidays) are
 * covered instead by tests/Feature/CollectionDateAdjustmentServiceTest.php,
 * which has real DB access for fixture rows.
 */
class BusinessCalendarServiceTest extends TestCase
{
    public function testIsWeekendPure(): void
    {
        // 2026-09-20 is a Sunday, 2026-09-19 a Saturday, 2026-09-18 a Friday.
        $this->assertTrue(BusinessCalendarService::isWeekendPure(new \DateTimeImmutable('2026-09-20')));
        $this->assertTrue(BusinessCalendarService::isWeekendPure(new \DateTimeImmutable('2026-09-19')));
        $this->assertFalse(BusinessCalendarService::isWeekendPure(new \DateTimeImmutable('2026-09-18')));
    }

    public function testWalkBackwardReturnsSameDateWhenAlreadyBusinessDay(): void
    {
        $friday = new \DateTimeImmutable('2026-09-18');
        $result = BusinessCalendarService::walkToBusinessDay($friday, -1, fn ($d) => !BusinessCalendarService::isWeekendPure($d));
        $this->assertSame('2026-09-18', $result->format('Y-m-d'));
    }

    public function testWalkBackwardOverAWeekend(): void
    {
        // Sunday 2026-09-20 -> should land on Friday 2026-09-18.
        $sunday = new \DateTimeImmutable('2026-09-20');
        $result = BusinessCalendarService::walkToBusinessDay($sunday, -1, fn ($d) => !BusinessCalendarService::isWeekendPure($d));
        $this->assertSame('2026-09-18', $result->format('Y-m-d'));
    }

    public function testWalkForwardOverAWeekend(): void
    {
        // Sunday 2026-09-20 -> should land on Monday 2026-09-21.
        $sunday = new \DateTimeImmutable('2026-09-20');
        $result = BusinessCalendarService::walkToBusinessDay($sunday, 1, fn ($d) => !BusinessCalendarService::isWeekendPure($d));
        $this->assertSame('2026-09-21', $result->format('Y-m-d'));
    }

    /** Multiple consecutive non-business days -- a holiday sitting directly against a weekend, not just a single day either side. */
    public function testWalkBackwardOverMultipleConsecutiveNonBusinessDays(): void
    {
        // Fake calendar: Friday 2026-09-18 is ALSO "closed" (e.g. a holiday),
        // plus the weekend behind it -- previous business day from Sunday
        // 2026-09-20 must skip past Sat/Sun AND the closed Friday, landing
        // on Thursday 2026-09-17.
        $closedDates = ['2026-09-18'];
        $isBusinessDay = function (\DateTimeImmutable $d) use ($closedDates) {
            return !BusinessCalendarService::isWeekendPure($d) && !in_array($d->format('Y-m-d'), $closedDates, true);
        };
        $sunday = new \DateTimeImmutable('2026-09-20');
        $result = BusinessCalendarService::walkToBusinessDay($sunday, -1, $isBusinessDay);
        $this->assertSame('2026-09-17', $result->format('Y-m-d'));
    }

    public function testWalkAcrossMonthBoundary(): void
    {
        // Normal pay date 2027-01-01 (a Friday, so not itself the issue --
        // force it "closed" to prove the walk crosses into December).
        $closedDates = ['2027-01-01'];
        $isBusinessDay = function (\DateTimeImmutable $d) use ($closedDates) {
            return !BusinessCalendarService::isWeekendPure($d) && !in_array($d->format('Y-m-d'), $closedDates, true);
        };
        $newYearsDay = new \DateTimeImmutable('2027-01-01');
        $result = BusinessCalendarService::walkToBusinessDay($newYearsDay, -1, $isBusinessDay);
        $this->assertSame('2026-12-31', $result->format('Y-m-d'));
    }

    public function testThrowsIfNoBusinessDayFoundWithinCap(): void
    {
        $this->expectException(\RuntimeException::class);
        BusinessCalendarService::walkToBusinessDay(new \DateTimeImmutable('2026-09-20'), -1, fn ($d) => false);
    }
}
