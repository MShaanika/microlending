<?php

namespace App\Services;

/**
 * Parses a borrower-supplied bank statement CSV export into raw transaction
 * rows -- unlike app/Services/BankStatementCsvParser.php (which expects our
 * own bank-reconciliation feed's fixed header), this ingests an arbitrary
 * export from whichever bank the applicant uses, so header names are
 * matched against a broader alias list and either a single signed "amount"
 * column or separate debit/credit columns are accepted.
 */
class BorrowerBankStatementCsvParser
{
    private const DATE_ALIASES = ['date', 'transaction date'];
    private const DESCRIPTION_ALIASES = ['description', 'narration', 'details'];
    private const AMOUNT_ALIASES = ['amount'];
    private const DEBIT_ALIASES = ['debit', 'money out', 'withdrawal'];
    private const CREDIT_ALIASES = ['credit', 'money in', 'deposit'];
    private const BALANCE_ALIASES = ['balance', 'running balance'];

    /**
     * @return array{rows: array, errors: array}
     */
    public static function parse(string $filePath): array
    {
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

        $dateCol = self::firstMatch($columnIndex, self::DATE_ALIASES);
        $descCol = self::firstMatch($columnIndex, self::DESCRIPTION_ALIASES);
        $amountCol = self::firstMatch($columnIndex, self::AMOUNT_ALIASES);
        $debitCol = self::firstMatch($columnIndex, self::DEBIT_ALIASES);
        $creditCol = self::firstMatch($columnIndex, self::CREDIT_ALIASES);
        $balanceCol = self::firstMatch($columnIndex, self::BALANCE_ALIASES);

        if ($dateCol === null || $descCol === null) {
            fclose($handle);
            return ['rows' => [], 'errors' => ['Missing required column(s): a date column and a description/narration column are both required.']];
        }
        if ($amountCol === null && ($debitCol === null || $creditCol === null)) {
            fclose($handle);
            return ['rows' => [], 'errors' => ['Missing amount column(s): expected either a single "amount" column, or both a debit/money out and a credit/money in column.']];
        }

        $rows = [];
        $errors = [];
        $lineNo = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNo++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // skip blank lines
            }

            $get = fn (?int $col) => $col !== null ? trim((string) ($row[$col] ?? '')) : '';

            $rawDate = $get($dateCol);
            $timestamp = strtotime($rawDate);
            if ($rawDate === '' || $timestamp === false) {
                $errors[] = "Line $lineNo: invalid date \"$rawDate\".";
                continue;
            }

            $description = $get($descCol);

            if ($amountCol !== null) {
                $amount = self::parseAmount($get($amountCol));
                if ($amount === null) {
                    $errors[] = "Line $lineNo: invalid amount.";
                    continue;
                }
                $type = $amount < 0 ? 'Debit' : 'Credit';
                $amount = abs($amount);
            } else {
                $debit = self::parseAmount($get($debitCol)) ?? 0.0;
                $credit = self::parseAmount($get($creditCol)) ?? 0.0;
                if ($debit > 0) {
                    $type = 'Debit';
                    $amount = $debit;
                } elseif ($credit > 0) {
                    $type = 'Credit';
                    $amount = $credit;
                } else {
                    continue; // both zero/blank -- not a real transaction row
                }
            }

            $runningBalance = $balanceCol !== null ? self::parseAmount($get($balanceCol)) : null;

            $rows[] = [
                'date' => date('Y-m-d', $timestamp),
                'description' => $description,
                'amount' => round($amount, 2),
                'type' => $type,
                'running_balance' => $runningBalance,
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    private static function firstMatch(array $columnIndex, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            if (isset($columnIndex[$alias])) {
                return $columnIndex[$alias];
            }
        }
        return null;
    }

    private static function parseAmount(string $value): ?float
    {
        if ($value === '') {
            return null;
        }
        $clean = str_replace([',', ' '], '', $value);
        $negative = false;
        if (str_starts_with($clean, '(') && str_ends_with($clean, ')')) {
            $negative = true;
            $clean = substr($clean, 1, -1);
        }
        if (!is_numeric($clean)) {
            return null;
        }
        $result = (float) $clean;
        return round($negative ? -$result : $result, 2);
    }
}
