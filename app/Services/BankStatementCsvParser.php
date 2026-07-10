<?php

namespace App\Services;

/**
 * Parses a bank-provided CSV export into rows ready for
 * accounting_bank_statement. Expected header (case-insensitive, any
 * order): date, reference, description, money_in, money_out, balance.
 */
class BankStatementCsvParser
{
    private const REQUIRED_COLUMNS = ['date', 'description', 'money_in', 'money_out', 'balance'];

    /**
     * @return array{rows: array, errors: array}
     */
    public static function parse(string $filePath): array
    {
        $errors = [];
        $rows = [];

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['rows' => [], 'errors' => ['Could not read the uploaded file.']];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return ['rows' => [], 'errors' => ['The file is empty.']];
        }

        $columnIndex = [];
        foreach ($header as $i => $name) {
            $columnIndex[strtolower(trim($name))] = $i;
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columnIndex));
        if (!empty($missing)) {
            fclose($handle);
            return ['rows' => [], 'errors' => ['Missing required column(s): ' . implode(', ', $missing) . '. Expected header: date, reference, description, money_in, money_out, balance.']];
        }

        $lineNo = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNo++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // skip blank lines
            }

            $get = fn (string $col) => trim((string) ($row[$columnIndex[$col]] ?? ''));

            $rawDate = $get('date');
            $timestamp = strtotime($rawDate);
            if ($rawDate === '' || $timestamp === false) {
                $errors[] = "Line $lineNo: invalid date \"$rawDate\".";
                continue;
            }

            $moneyIn = self::parseAmount($get('money_in'));
            $moneyOut = self::parseAmount($get('money_out'));
            $balance = self::parseAmount($get('balance'));

            if ($moneyIn === null || $moneyOut === null || $balance === null) {
                $errors[] = "Line $lineNo: invalid amount.";
                continue;
            }

            $rows[] = [
                'transaction_date' => date('Y-m-d', $timestamp),
                'reference_no' => isset($columnIndex['reference']) ? $get('reference') : '',
                'description' => $get('description'),
                'money_in' => $moneyIn,
                'money_out' => $moneyOut,
                'balance' => $balance,
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    private static function parseAmount(string $value): ?float
    {
        if ($value === '') {
            return 0.0;
        }
        $clean = str_replace([',', ' '], '', $value);
        if (!is_numeric($clean)) {
            return null;
        }
        return round((float) $clean, 2);
    }
}
