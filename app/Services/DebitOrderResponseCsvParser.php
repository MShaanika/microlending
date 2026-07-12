<?php

namespace App\Services;

/**
 * Parses a bank's debit order response file into rows ready to update
 * debit_order_run_lines. This is a generic, documented CSV format -- no
 * real bank spec exists yet, so this can be swapped for one later without
 * changing the run lifecycle, only this parser (and the matching exporter
 * in DebitOrderRunController::export()).
 *
 * Expected header (case-insensitive, any order): reference, status,
 * response_code, response_message. `reference` must match a line's
 * bank_reference from the outgoing collection file; `status` must be one
 * of Successful, Failed, Returned.
 */
class DebitOrderResponseCsvParser
{
    private const REQUIRED_COLUMNS = ['reference', 'status'];
    private const ALLOWED_STATUSES = ['Successful', 'Failed', 'Returned'];

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
            return ['rows' => [], 'errors' => ['Missing required column(s): ' . implode(', ', $missing) . '. Expected header: reference, status, response_code, response_message.']];
        }

        $lineNo = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNo++;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // skip blank lines
            }

            $get = fn (string $col) => isset($columnIndex[$col]) ? trim((string) ($row[$columnIndex[$col]] ?? '')) : '';

            $reference = $get('reference');
            $status = $get('status');

            if ($reference === '') {
                $errors[] = "Line $lineNo: missing reference.";
                continue;
            }
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                $errors[] = "Line $lineNo: status must be one of " . implode(', ', self::ALLOWED_STATUSES) . ", got \"$status\".";
                continue;
            }

            $rows[] = [
                'reference' => $reference,
                'status' => $status,
                'response_code' => $get('response_code'),
                'response_message' => $get('response_message'),
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }
}
