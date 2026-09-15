<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Successful Transaction with Detail Selection" report
 * export (0014) -- a richer version of the plain Successful Transactions
 * report with the same collection data (installment no, collection amount,
 * successful date) plus both "Merchant System Contract No" and its own
 * "Contract Reference" column. "Contract Reference" is used for matching
 * (via CollexiaMandateLookupService, same as every other import path) since
 * that's the value Collexia's other endpoints (Download Payments, Mandate
 * Enquiry) also use -- "Merchant System Contract No" is Collexia's own
 * internal id for the mandate, not necessarily the same string we'd
 * recognise on our side for an API-placed mandate.
 *
 * Rows are shaped identically to CollexiaSuccessfulTransactionsParser's
 * output (merchant_system_contract_no/installment_no/scheduled_date/
 * installment_amount/successful_date/collection_amount/client_name) so
 * DebitOrderCollectionController::store()'s existing 'Successful' posting
 * branch handles both report types without any special-casing.
 */
class CollexiaSuccessfulTransactionDetailParser
{
    private const REQUIRED_HEADERS = [
        'Merchant System Contract No', 'Contract Reference', 'Successful Date', 'Scheduled Date',
        'Number Of Installment', 'Installment Amount', 'Collection Amount', 'Client Name',
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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Successful Transaction with Detail Selection');
        [$headerRow, $colByHeader, $error] = CollexiaReportReader::locateHeaders($sheet, self::REQUIRED_HEADERS);
        if ($error !== null) {
            return ['rows' => [], 'errors' => [$error]];
        }

        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $contractReference = trim((string) $sheet->getCell($colByHeader['Contract Reference'] . $r)->getValue());
            if ($contractReference === '') {
                continue;
            }

            $rows[] = [
                'merchant_system_contract_no' => $contractReference,
                'client_name' => trim((string) $sheet->getCell($colByHeader['Client Name'] . $r)->getValue()),
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Scheduled Date'] . $r)),
                'installment_amount' => (float) $sheet->getCell($colByHeader['Installment Amount'] . $r)->getValue(),
                'installment_no' => (int) $sheet->getCell($colByHeader['Number Of Installment'] . $r)->getValue(),
                'successful_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Successful Date'] . $r)),
                'collection_amount' => (float) $sheet->getCell($colByHeader['Collection Amount'] . $r)->getValue(),
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
