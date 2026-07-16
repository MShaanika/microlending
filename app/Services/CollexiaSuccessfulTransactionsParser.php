<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Successful Transactions" report export -- one row per
 * installment Collexia actually collected, with the real collected amount
 * and date. This is the authoritative source for posting real payments
 * (unlike the "Scheduled Installments" report, which has no collection
 * date/amount at all -- see CollexiaScheduledInstallmentsParser).
 */
class CollexiaSuccessfulTransactionsParser
{
    private const REQUIRED_HEADERS = [
        'Merchant System Contract No', 'Number Of Installment', 'Installment Amount',
        'Collection Amount', 'Successful Date', 'Scheduled Date', 'Client Number', 'Client Name',
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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Successful Transactions');
        [$headerRow, $colByHeader, $error] = CollexiaReportReader::locateHeaders($sheet, self::REQUIRED_HEADERS);
        if ($error !== null) {
            return ['rows' => [], 'errors' => [$error]];
        }

        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $contractNo = trim((string) $sheet->getCell($colByHeader['Merchant System Contract No'] . $r)->getValue());
            if ($contractNo === '') {
                continue;
            }

            $rows[] = [
                'merchant_system_contract_no' => $contractNo,
                'installment_no' => (int) $sheet->getCell($colByHeader['Number Of Installment'] . $r)->getValue(),
                'installment_amount' => (float) $sheet->getCell($colByHeader['Installment Amount'] . $r)->getValue(),
                'collection_amount' => (float) $sheet->getCell($colByHeader['Collection Amount'] . $r)->getValue(),
                'successful_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Successful Date'] . $r)),
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Scheduled Date'] . $r)),
                'client_number' => trim((string) $sheet->getCell($colByHeader['Client Number'] . $r)->getValue()),
                'client_name' => trim((string) $sheet->getCell($colByHeader['Client Name'] . $r)->getValue()),
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
