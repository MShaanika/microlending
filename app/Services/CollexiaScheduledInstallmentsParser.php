<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Scheduled Installments" report export (one row per
 * installment across all registered contracts, with Payment Date/Amount
 * populated once actually collected). Column order is read from its own
 * header row rather than assumed, since Collexia's own report builder lets
 * users reorder/hide columns.
 */
class CollexiaScheduledInstallmentsParser
{
    private const REQUIRED_HEADERS = ['Merchant System Contract No', 'Installment Status', 'Scheduled Date', 'Installment Amount', 'No', 'Payment Date', 'Payment Amount'];

    /**
     * @return array{rows: array, errors: string[]}
     */
    public static function parse(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            return ['rows' => [], 'errors' => ['Could not read the file: ' . $e->getMessage()]];
        }

        $sheet = $spreadsheet->getSheetByName('Scheduled Installments') ?? $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        // The header row is the first row whose first cell reads "GID" --
        // the report has a title/count row and a blank row above it.
        $headerRow = null;
        for ($r = 1; $r <= min($highestRow, 10); $r++) {
            if (trim((string) $sheet->getCell("A{$r}")->getValue()) === 'GID') {
                $headerRow = $r;
                break;
            }
        }

        if ($headerRow === null) {
            return ['rows' => [], 'errors' => ['Could not find the header row (expected a "GID" column) -- is this a Scheduled Installments export?']];
        }

        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
        $headers = [];
        for ($c = 1; $c <= $highestColIndex; $c++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
            $headers[$colLetter] = trim((string) $sheet->getCell($colLetter . $headerRow)->getValue());
        }

        $missing = array_diff(self::REQUIRED_HEADERS, array_values($headers));
        if (!empty($missing)) {
            return ['rows' => [], 'errors' => ['Missing expected column(s): ' . implode(', ', $missing)]];
        }

        $colByHeader = array_flip($headers);
        $rows = [];
        $errors = [];

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $contractNo = trim((string) $sheet->getCell($colByHeader['Merchant System Contract No'] . $r)->getValue());
            if ($contractNo === '') {
                continue;
            }

            $paymentDateCell = $sheet->getCell($colByHeader['Payment Date'] . $r);
            $scheduledDateCell = $sheet->getCell($colByHeader['Scheduled Date'] . $r);

            $rows[] = [
                'merchant_system_contract_no' => $contractNo,
                'installment_status' => trim((string) $sheet->getCell($colByHeader['Installment Status'] . $r)->getValue()),
                'scheduled_date' => self::readDate($scheduledDateCell),
                'installment_amount' => (float) $sheet->getCell($colByHeader['Installment Amount'] . $r)->getValue(),
                'installment_no' => (int) $sheet->getCell($colByHeader['No'] . $r)->getValue(),
                'payment_date' => self::readDate($paymentDateCell),
                'payment_amount' => is_numeric($sheet->getCell($colByHeader['Payment Amount'] . $r)->getValue())
                    ? (float) $sheet->getCell($colByHeader['Payment Amount'] . $r)->getValue()
                    : null,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    private static function readDate($cell): ?string
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
