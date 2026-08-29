<?php

namespace App\Services;

use App\Models\SystemSetting;

/**
 * Minimal business-hours calculator for SLA due-date calculation (Part
 * 18). Deliberately narrow: weekday Mon-Fri within an admin-configured
 * daily window (system_settings business_hours_start/end, default
 * 08:00-17:00), no public-holiday calendar -- a full holiday calendar
 * is a real, separate feature this phase does not attempt to guess at.
 * An SLA policy that needs holiday-awareness should stay
 * business_hours_aware = 0 (24/7 clock) until that's built.
 */
class BusinessHoursService
{
    /** Adds $minutes of BUSINESS time to $start, skipping weekends and outside-hours gaps entirely. */
    public static function addBusinessMinutes(\DateTimeImmutable $start, int $minutes): \DateTimeImmutable
    {
        [$startHour, $startMin] = self::windowStart();
        [$endHour, $endMin] = self::windowEnd();

        $cursor = self::snapIntoWindow($start, $startHour, $startMin, $endHour, $endMin);
        $remaining = $minutes;

        while ($remaining > 0) {
            $dayEnd = $cursor->setTime($endHour, $endMin);
            $minutesLeftToday = max(0, ($dayEnd->getTimestamp() - $cursor->getTimestamp()) / 60);

            if ($remaining <= $minutesLeftToday) {
                return $cursor->modify("+{$remaining} minutes");
            }

            $remaining -= $minutesLeftToday;
            $cursor = self::nextBusinessDayStart($cursor, $startHour, $startMin);
        }

        return $cursor;
    }

    private static function snapIntoWindow(\DateTimeImmutable $dt, int $startHour, int $startMin, int $endHour, int $endMin): \DateTimeImmutable
    {
        while (self::isWeekend($dt)) {
            $dt = $dt->modify('+1 day')->setTime($startHour, $startMin);
        }
        $windowStart = $dt->setTime($startHour, $startMin);
        $windowEnd = $dt->setTime($endHour, $endMin);

        if ($dt < $windowStart) {
            return $windowStart;
        }
        if ($dt > $windowEnd) {
            return self::nextBusinessDayStart($dt, $startHour, $startMin);
        }
        return $dt;
    }

    private static function nextBusinessDayStart(\DateTimeImmutable $dt, int $startHour, int $startMin): \DateTimeImmutable
    {
        $next = $dt->modify('+1 day')->setTime($startHour, $startMin);
        while (self::isWeekend($next)) {
            $next = $next->modify('+1 day');
        }
        return $next;
    }

    private static function isWeekend(\DateTimeImmutable $dt): bool
    {
        return in_array((int) $dt->format('N'), [6, 7], true);
    }

    private static function windowStart(): array
    {
        return self::parseHm((new SystemSetting())->get('business_hours_start', '08:00'));
    }

    private static function windowEnd(): array
    {
        return self::parseHm((new SystemSetting())->get('business_hours_end', '17:00'));
    }

    private static function parseHm(?string $value): array
    {
        $parts = explode(':', $value ?: '08:00');
        return [(int) ($parts[0] ?? 8), (int) ($parts[1] ?? 0)];
    }
}
