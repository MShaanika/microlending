<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Failed Validation" report export (0048) -- installments
 * that failed a validation check (CDV, etc.) before ever reaching a
 * successful/unsuccessful collection attempt. Carries a Contract Reference
 * (not "Merchant System Contract No" -- this report is one of the few that
 * doesn't have that column at all) plus a Response Code/Description, but no
 * payment date/amount, so it's purely for visibility, same as
 * CollexiaUnsuccessfulTransactionsParser.
 */
class CollexiaFailedValidationParser
{
    private const ANCHOR_HEADER = 'Contract Reference';

    private const REQUIRED_HEADERS = [
        'Contract Reference', 'Response Code', 'Installment Status', 'Description',
        'Schedule Date', 'Installment Amount', 'Installment Number',
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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Failed Validation');
        [$headerRow, $colByHeader, $error] = CollexiaReportReader::locateHeaders($sheet, self::REQUIRED_HEADERS, 10, self::ANCHOR_HEADER);
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

            $responseCode = trim((string) $sheet->getCell($colByHeader['Response Code'] . $r)->getValue());
            $description = trim((string) $sheet->getCell($colByHeader['Description'] . $r)->getValue());

            $rows[] = [
                'merchant_system_contract_no' => $contractReference,
                'installment_status' => $responseCode !== '' ? ($responseCode . ' - ' . $description) : $description,
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Schedule Date'] . $r)),
                'installment_amount' => (float) $sheet->getCell($colByHeader['Installment Amount'] . $r)->getValue(),
                'installment_no' => (int) $sheet->getCell($colByHeader['Installment Number'] . $r)->getValue(),
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
