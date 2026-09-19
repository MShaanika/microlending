<?php

namespace App\Services;

use App\Models\PublicHoliday;

/**
 * Reusable weekend + public-holiday-aware calendar engine. Deliberately
 * has NO knowledge of lending, HR, or leave -- it answers exactly one
 * question ("is this date a business day, and if not, where's the
 * nearest one in direction X") and nothing else. Reads only
 * public_holidays (see that table's header comment: starts empty,
 * never algorithmically guesses a Namibian holiday date).
 *
 * previousBusinessDay()/nextBusinessDay() walk one day at a time so a
 * holiday sitting directly against a weekend (or several holidays in a
 * row) is handled correctly, not just a single-day check either side.
 */
class BusinessCalendarService
{
    private const MAX_WALK_DAYS = 15;

    private PublicHoliday $holidays;

    public function __construct(?PublicHoliday $holidays = null)
    {
        $this->holidays = $holidays ?? new PublicHoliday();
    }

    public function isWeekend(\DateTimeImmutable $date): bool
    {
        return self::isWeekendPure($date);
    }

    public function isPublicHoliday(\DateTimeImmutable $date): bool
    {
        return $this->holidays->isActiveHoliday($date->format('Y-m-d'));
    }

    public function isBusinessDay(\DateTimeImmutable $date): bool
    {
        return !$this->isWeekend($date) && !$this->isPublicHoliday($date);
    }

    public function previousBusinessDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return self::walkToBusinessDay($date, -1, fn (\DateTimeImmutable $d) => $this->isBusinessDay($d));
    }

    public function nextBusinessDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return self::walkToBusinessDay($date, 1, fn (\DateTimeImmutable $d) => $this->isBusinessDay($d));
    }

    /** Pure, DB-free weekend check -- extracted so the walk logic below is unit-testable without a database. Saturday=6, Sunday=7 (ISO-8601, DateTime's 'N' format). */
    public static function isWeekendPure(\DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), [6, 7], true);
    }

    /**
     * The actual walking algorithm, fully unit-testable via an injected
     * $isBusinessDayFn (see tests/Unit/BusinessCalendarServiceTest.php)
     * -- no database required to verify it correctly steps over multiple
     * consecutive non-business days. Throws if MAX_WALK_DAYS is exceeded,
     * which signals bad holiday data (e.g. an accidental multi-week
     * holiday range) rather than looping forever.
     *
     * @param -1|1 $direction
     */
    public static function walkToBusinessDay(\DateTimeImmutable $date, int $direction, callable $isBusinessDayFn): \DateTimeImmutable
    {
        $current = $date;
        $steps = 0;
        while (!$isBusinessDayFn($current)) {
            $current = $current->modify(($direction > 0 ? '+1 day' : '-1 day'));
            $steps++;
            if ($steps > self::MAX_WALK_DAYS) {
                throw new \RuntimeException(
                    'Could not find a business day within ' . self::MAX_WALK_DAYS . ' days of ' . $date->format('Y-m-d')
                    . ' -- check public_holidays for an unintentionally long consecutive run.'
                );
            }
        }
        return $current;
    }
}
