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
     * Scans the first $searchRows rows for the one containing a
     * "Merchant System Contract No" cell -- present in every Collexia report
     * of this family -- and treats that as the header row.
     *
     * @return array{0: ?int, 1: array<string,string>, 2: ?string} [headerRow, headerName => columnLetter map, error]
     */
    public static function locateHeaders(Worksheet $sheet, array $requiredHeaders, int $searchRows = 10): array
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

            if (in_array('Merchant System Contract No', $rowValues, true)) {
                $missing = array_diff($requiredHeaders, array_values($rowValues));
                if (!empty($missing)) {
                    return [null, [], 'Missing expected column(s): ' . implode(', ', $missing)];
                }
                return [$r, array_flip($rowValues), null];
            }
        }

        return [null, [], 'Could not find the header row (expected a "Merchant System Contract No" column) -- is this a Collexia report export?'];
    }

    /**
     * Identifies which of the three known Collexia report exports a file is,
     * purely from its sheet names, so the caller can route to the matching
     * parser without staff having to say which report they're uploading.
     */
    public static function detectReportType(string $filePath): ?string
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            return null;
        }

        $names = $spreadsheet->getSheetNames();
        foreach ($names as $name) {
            if (stripos($name, 'Unsuccessful Transactions') !== false) {
                return 'Unsuccessful';
            }
        }
        foreach ($names as $name) {
            if (stripos($name, 'Successful Transactions') !== false) {
                return 'Successful';
            }
        }
        foreach ($names as $name) {
            if (stripos($name, 'Scheduled Installments') !== false) {
                return 'Scheduled';
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
