<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Successful Transactions (simplified)" report export
 * (0051) -- a reduced version of the full Successful Transactions report
 * with NO contract reference and NO installment number at all, only a
 * "Client Number" (the client-facing loan reference). Since that's not
 * enough to reliably resolve which local mandate/installment this belongs
 * to (a wrong guess here would misattribute a real collection amount to
 * the wrong loan), or to safely de-duplicate against a repeat/overlapping
 * import (no installment number to key on), this import is deliberately
 * visibility-only: every row is recorded unmatched (debit_order_id/loan_id
 * null) and never posts a Payment. Use the full "Successful Transaction
 * with Detail Selection" report (CollexiaSuccessfulTransactionDetailParser)
 * or the plain Successful Transactions report instead when the goal is
 * actually reconciling/posting payments.
 */
class CollexiaSuccessfulTransactionsSimplifiedParser
{
    private const ANCHOR_HEADER = 'StatementReference';

    private const REQUIRED_HEADERS = [
        'Successful Date', 'Scheduled Date', 'Client Number', 'Client Name', 'Collection Amount', 'StatementReference',
    ];

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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Successful Transactions (simplified)');
        [$headerRow, $colByHeader, $error] = CollexiaReportReader::locateHeaders($sheet, self::REQUIRED_HEADERS, 10, self::ANCHOR_HEADER);
        if ($error !== null) {
            return ['rows' => [], 'errors' => [$error]];
        }

        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $statementRef = trim((string) $sheet->getCell($colByHeader['StatementReference'] . $r)->getValue());
            if ($statementRef === '') {
                continue;
            }

            $rows[] = [
                'statement_reference' => $statementRef,
                'client_number' => trim((string) $sheet->getCell($colByHeader['Client Number'] . $r)->getValue()),
                'client_name' => trim((string) $sheet->getCell($colByHeader['Client Name'] . $r)->getValue()),
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Scheduled Date'] . $r)),
                'successful_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Successful Date'] . $r)),
                'collection_amount' => (float) $sheet->getCell($colByHeader['Collection Amount'] . $r)->getValue(),
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
