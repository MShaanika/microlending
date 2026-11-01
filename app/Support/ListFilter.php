<?php

namespace App\Support;

/**
 * The common filters of a list screen: search text, status, a received/created date range and an amount range. It reads them from the
 * query string, cleans them (anything that is not a real date or a plain number is ignored, a backwards range is put right) and turns them
 * into SQL conditions with bound parameters, so every list filters the same way. Pure: no database, no globals besides what it is given.
 */
class ListFilter
{
    /** Longest list an export may return. */
    public const EXPORT_LIMIT = 5000;

    /**
     * @param array<string, mixed> $get usually $_GET
     * @return array{q: string, status: string, from: ?string, to: ?string, min: ?float, max: ?float, export: bool}
     */
    public static function fromRequest(array $get): array
    {
        [$from, $to] = self::dateRange(self::text($get['from'] ?? ''), self::text($get['to'] ?? ''));
        [$min, $max] = self::amountRange(self::text($get['min'] ?? ''), self::text($get['max'] ?? ''));
        return [
            'q' => mb_substr(self::text($get['q'] ?? ''), 0, 100),
            'status' => mb_substr(self::text($get['status'] ?? ''), 0, 60),
            'from' => $from,
            'to' => $to,
            'min' => $min,
            'max' => $max,
            'export' => ($get['export'] ?? '') === 'csv',
        ];
    }

    /** True when a date or amount range is set (the list may then be longer than the everyday view). */
    public static function isRange(array $filter): bool
    {
        return ($filter['from'] ?? null) !== null || ($filter['to'] ?? null) !== null || ($filter['min'] ?? null) !== null || ($filter['max'] ?? null) !== null;
    }

    /** @return array{0: ?string, 1: ?string} inclusive Y-m-d dates, swapped when typed the wrong way round */
    public static function dateRange(string $from, string $to): array
    {
        $from = self::validDate($from);
        $to = self::validDate($to);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    /** @return array{0: ?float, 1: ?float} */
    public static function amountRange(string $min, string $max): array
    {
        $min = self::validAmount($min);
        $max = self::validAmount($max);
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }
        return [$min, $max];
    }

    /**
     * " AND col >= ? AND col < ?" for the days from..to inclusive, whether the column holds a DATE or a DATETIME. The column is trusted SQL
     * written by the caller (never user input); the dates are bound. Returns '' when no date is set.
     */
    public static function dateSql(string $column, ?string $from, ?string $to, array &$params): string
    {
        $sql = '';
        if ($from !== null) {
            $sql .= " AND $column >= ?";
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $sql .= " AND $column < ?";
            $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        }
        return $sql;
    }

    /** " AND col >= ? AND col <= ?" for an amount range; '' when none is set. */
    public static function amountSql(string $column, ?float $min, ?float $max, array &$params): string
    {
        $sql = '';
        if ($min !== null) {
            $sql .= " AND $column >= ?";
            $params[] = $min;
        }
        if ($max !== null) {
            $sql .= " AND $column <= ?";
            $params[] = $max;
        }
        return $sql;
    }

    /**
     * " AND (a LIKE ? OR b LIKE ? ...)" matching the text against any of the given columns; '' when the text is empty. Wildcard characters typed by
     * the user are escaped so "50%" searches for "50%".
     *
     * @param string[] $columns trusted SQL column expressions written by the caller
     */
    public static function searchSql(array $columns, string $text, array &$params): string
    {
        if ($text === '' || $columns === []) {
            return '';
        }
        $like = '%' . addcslashes($text, '\\%_') . '%';
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = "$column LIKE ?";
            $params[] = $like;
        }
        return ' AND (' . implode(' OR ', $parts) . ')';
    }

    /** The current query string with export=csv added, for the Download CSV link. */
    public static function exportQuery(array $get): string
    {
        unset($get['export'], $get['page']);
        $get = array_filter($get, static fn ($v) => $v !== '' && $v !== null);
        $get['export'] = 'csv';
        return http_build_query($get);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function validDate(string $value): ?string
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function validAmount(string $value): ?float
    {
        $value = str_replace([' ', ','], '', $value);
        return $value !== '' && preg_match('/^\d+(\.\d{1,2})?$/', $value) ? (float) $value : null;
    }
}
