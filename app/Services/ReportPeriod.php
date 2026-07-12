<?php

namespace App\Services;

/**
 * Turns a (period type, year, month/quarter) selection into a single
 * [start_date, end_date] range. Every report query then just does
 * `WHERE date_col BETWEEN ? AND ?` -- no dynamic IN(...) clause building,
 * no reference-array bind_param gymnastics, one code path for month,
 * quarter, and year views instead of one per report.
 */
class ReportPeriod
{
    public static function fromRequest(array $params): array
    {
        $type = in_array($params['period_type'] ?? '', ['month', 'quarter', 'year'], true)
            ? $params['period_type']
            : 'month';
        $year = (int) ($params['year'] ?? date('Y'));
        $month = (int) ($params['month'] ?? date('n'));
        $quarter = (int) ($params['quarter'] ?? (int) ceil(date('n') / 3));

        return self::range($type, $year, $month, $quarter);
    }

    /**
     * @return array{type:string, year:int, start:string, end:string, label:string}
     */
    public static function range(string $type, int $year, int $month, int $quarter): array
    {
        if ($type === 'quarter') {
            $quarter = max(1, min(4, $quarter));
            $startMonth = ($quarter - 1) * 3 + 1;
            $start = sprintf('%04d-%02d-01', $year, $startMonth);
            $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $startMonth + 2)));
            return [
                'type' => 'quarter',
                'year' => $year,
                'month' => null,
                'quarter' => $quarter,
                'start' => $start,
                'end' => $end,
                'label' => "Q{$quarter} {$year}",
            ];
        }

        if ($type === 'year') {
            return [
                'type' => 'year',
                'year' => $year,
                'month' => null,
                'quarter' => null,
                'start' => "{$year}-01-01",
                'end' => "{$year}-12-31",
                'label' => (string) $year,
            ];
        }

        $month = max(1, min(12, $month));
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        return [
            'type' => 'month',
            'year' => $year,
            'month' => $month,
            'quarter' => null,
            'start' => $start,
            'end' => $end,
            'label' => date('F Y', strtotime($start)),
        ];
    }
}
