<?php

namespace App\Support;

/**
 * The period a bank reconciliation is done for: a From and a To date (normally one calendar month).
 * Old links carried a single "as_of_date"; it still works and is read as the To date.
 */
final class ReconciliationPeriod
{
    /**
     * @param array<string, mixed> $input query string or form fields
     * @return array{from: string, to: string, error: ?string}
     */
    public static function resolve(array $input, string $today): array
    {
        $to = self::date($input['to_date'] ?? null) ?? self::date($input['as_of_date'] ?? null) ?? $today;
        $from = self::date($input['from_date'] ?? null) ?? self::monthStart($to);
        $error = null;
        if ($from > $to) {
            $error = 'The From date was after the To date, so the period was reset to the month of the To date.';
            $from = self::monthStart($to);
        }
        return ['from' => $from, 'to' => $to, 'error' => $error];
    }

    /** Query-string tail that carries the period (and the two display switches) through a redirect. */
    public static function query(array $input, string $today): string
    {
        $p = self::resolve($input, $today);
        $q = '&from_date=' . $p['from'] . '&to_date=' . $p['to'];
        if (!empty($input['include_earlier'])) {
            $q .= '&include_earlier=1';
        }
        if (!empty($input['unmatched_only'])) {
            $q .= '&unmatched_only=1';
        }
        return $q;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $value;
    }

    private static function monthStart(string $date): string
    {
        return substr($date, 0, 8) . '01';
    }
}
