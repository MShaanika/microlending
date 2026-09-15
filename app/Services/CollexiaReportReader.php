<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared reading helpers for Collexia's xlsx report exports (Successful
 * Transactions, Unsuccessful Transactions, Scheduled Installments, and any
 * future report of the same shape). Every one of these exports has a
 * title/record-count row, a blank row, then a header row -- but the column
 * order and count differs per report type, so headers are always located
 * and read by name rather than assumed position.
 */
class CollexiaReportReader
{
    /**
     * Picks the sheet whose name contains $nameContains (case-insensitive --
     * Collexia's own sheet names carry inconsistent trailing whitespace),
     * falling back to the active sheet if no match is found.
     */
    public static function findSheet(Spreadsheet $spreadsheet, string $nameContains): Worksheet
    {
        foreach ($spreadsheet->getSheetNames() as $name) {
            if (stripos($name, $nameContains) !== false) {
                return $spreadsheet->getSheetByName($name);
            }
        }
        return $spreadsheet->getActiveSheet();
    }

    /**
     * Scans the first $searchRows rows for the one containing an
     * $anchorHeader cell, and treats that as the header row. Every Collexia
     * report of this family has a title/record-count row and a blank row
     * before the real header, so the anchor can't just be "row 1" -- but
     * not every report shares the same anchor column (e.g. "Scheduled
     * installments forecast" and "Failed Validation" have no "Merchant
     * System Contract No" column at all), so callers for those report types
     * must pass a column that IS actually present in their report.
     *
     * @return array{0: ?int, 1: array<string,string>, 2: ?string} [headerRow, headerName => columnLetter map, error]
     */
    public static function locateHeaders(Worksheet $sheet, array $requiredHeaders, int $searchRows = 10, string $anchorHeader = 'Merchant System Contract No'): array
    {
        $highestCol = $sheet->getHighestDataColumn();
        $highestColIndex = Coordinate::columnIndexFromString($highestCol);
        $maxSearchRow = min($sheet->getHighestDataRow(), $searchRows);

        for ($r = 1; $r <= $maxSearchRow; $r++) {
            $rowValues = [];
            for ($c = 1; $c <= $highestColIndex; $c++) {
                $colLetter = Coordinate::stringFromColumnIndex($c);
                $rowValues[$colLetter] = trim((string) $sheet->getCell($colLetter . $r)->getValue());
            }

            if (in_array($anchorHeader, $rowValues, true)) {
                $missing = array_diff($requiredHeaders, array_values($rowValues));
                if (!empty($missing)) {
                    return [null, [], 'Missing expected column(s): ' . implode(', ', $missing)];
                }
                return [$r, array_flip($rowValues), null];
            }
        }

        return [null, [], 'Could not find the header row (expected a "' . $anchorHeader . '" column) -- is this a Collexia report export?'];
    }

    /**
     * Identifies which of Collexia's report exports a file is, purely from
     * its sheet names, so the caller can route to the matching parser
     * without staff having to say which report they're uploading.
     *
     * Order matters: several sheet names are substrings of others (e.g.
     * "Scheduled installments forecast" and "Scheduled Installments With
     * Detail Selection" both contain the plain "Scheduled Installments"
     * report's name, and "Successful Transactions (simplified)" contains
     * the plain "Successful Transactions" report's name) -- every specific
     * pattern is checked before the generic one it's a substring of, so a
     * newer/richer report never gets silently misrouted to the older
     * parser built for a narrower column set.
     */
    public static function detectReportType(string $filePath): ?string
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            return null;
        }

        $names = $spreadsheet->getSheetNames();
        $patterns = [
            'Failed Validation' => 'FailedValidation',
            'Successful Transactions (simplified)' => 'SuccessfulSimplified',
            'Successful Transaction with Detail Selection' => 'SuccessfulDetail',
            'Unsuccessful Transactions' => 'Unsuccessful',
            'Successful Transactions' => 'Successful',
            'Scheduled Installments With Detail Selection' => 'ScheduledDetail',
            'Scheduled installments forecast' => 'ScheduledForecast',
            'Scheduled Installments' => 'Scheduled',
            'Mandate Creation Audit Report' => 'MandateAudit',
        ];

        foreach ($patterns as $needle => $type) {
            foreach ($names as $name) {
                if (stripos($name, $needle) !== false) {
                    return $type;
                }
            }
        }

        return null;
    }

    public static function readDate($cell): ?string
    {
        $value = $cell->getValue();
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        $ts = strtotime((string) $value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
