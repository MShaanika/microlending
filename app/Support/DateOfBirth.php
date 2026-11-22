<?php

namespace App\Support;

/**
 * One place that decides whether a typed date of birth is believable.
 * Used by every entry point that stores a DOB (public intake, back-office
 * Add Borrower, borrower edit) and by the credit-check readiness check, so
 * the same bad value (e.g. today's date typed by mistake -- production
 * application #70, 2026-11-19) is refused everywhere with the same words.
 *
 * PHP 8.0 compatible (no readonly/enums): the live servers run 8.0-8.4.
 */
final class DateOfBirth
{
    public const MIN_AGE = 18;
    public const MAX_AGE = 100;

    /**
     * Returns null when the value is acceptable, otherwise one plain-language
     * sentence the applicant or staff member can act on.
     */
    public static function check(?string $value, ?string $today = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'Date of birth is missing.';
        }
        $date = self::parse($value);
        if ($date === null) {
            return 'Date of birth "' . self::safe($value) . '" is not a real date. Use the format YYYY-MM-DD (for example 1990-05-23).';
        }

        $todayDate = new \DateTimeImmutable($today ?? 'today');
        if ($date > $todayDate) {
            return 'Date of birth ' . $date->format('Y-m-d') . ' is in the future.';
        }
        $age = (int) $date->diff($todayDate)->y;
        if ($age < self::MIN_AGE) {
            return 'Date of birth ' . $date->format('Y-m-d') . ' makes the applicant ' . $age . ' years old. Applicants must be at least ' . self::MIN_AGE . '. It may be today\'s date typed by mistake.';
        }
        if ($age > self::MAX_AGE) {
            return 'Date of birth ' . $date->format('Y-m-d') . ' makes the applicant ' . $age . ' years old, which is not believable. Check the year.';
        }
        return null;
    }

    /** Canonical Y-m-d, or null when the text is not a real calendar date. */
    public static function normalize(?string $value): ?string
    {
        $date = self::parse(trim((string) $value));
        return $date ? $date->format('Y-m-d') : null;
    }

    private static function parse(string $value): ?\DateTimeImmutable
    {
        // Accept 2026-11-19 and 2026-11-19T00:00:00 (what the DATE column and the vendor use).
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2})?)?$/', $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null; // strtotime() would silently roll 2026-02-31 over to March
        }
        return new \DateTimeImmutable($m[1] . '-' . $m[2] . '-' . $m[3]);
    }

    private static function safe(string $value): string
    {
        return mb_substr(preg_replace('/[^\p{L}\p{N}\-\/ .:]/u', '', $value) ?? '', 0, 30);
    }
}
