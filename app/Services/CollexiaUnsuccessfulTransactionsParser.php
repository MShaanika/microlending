<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Unsuccessful Transactions" report export -- one row per
 * installment collection attempt that failed (e.g. Insufficient Funds), with
 * the rejection reason and date. No payment is ever posted from this report;
 * rows are recorded purely so staff/collectors can see and follow up on
 * failed collections.
 */
class CollexiaUnsuccessfulTransactionsParser
{
    private const REQUIRED_HEADERS = [
        'Merchant System Contract No', 'Number Of Installment', 'Installment Amount',
        'Installment Status', 'Rejection Date', 'Scheduled Date', 'Client Number', 'Client Name',
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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Unsuccessful Transactions');
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
                'installment_status' => trim((string) $sheet->getCell($colByHeader['Installment Status'] . $r)->getValue()),
                'rejection_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Rejection Date'] . $r)),
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Scheduled Date'] . $r)),
                'client_number' => trim((string) $sheet->getCell($colByHeader['Client Number'] . $r)->getValue()),
                'client_name' => trim((string) $sheet->getCell($colByHeader['Client Name'] . $r)->getValue()),
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
