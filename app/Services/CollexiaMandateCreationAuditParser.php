<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Mandate Creation Audit Report" export (0057) -- an
 * audit trail of WHEN each mandate was created, not a collection outcome.
 * No installment status/payment data exists for this report type at all;
 * imported purely for visibility (e.g. cross-checking that a mandate we
 * think we placed actually shows up on Collexia's side, and when).
 */
class CollexiaMandateCreationAuditParser
{
    private const REQUIRED_HEADERS = [
        'Merchant System Contract No', 'Client Name', 'Creation Date', 'Scheduled Date',
        'No Of Installments', 'Installment Amount',
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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Mandate Creation Audit Report');
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

            $creationDate = CollexiaReportReader::readDate($sheet->getCell($colByHeader['Creation Date'] . $r));
            $noOfInstallments = (int) $sheet->getCell($colByHeader['No Of Installments'] . $r)->getValue();

            $rows[] = [
                'merchant_system_contract_no' => $contractNo,
                'client_name' => trim((string) $sheet->getCell($colByHeader['Client Name'] . $r)->getValue()),
                'installment_status' => 'Mandate Created' . ($creationDate ? ' (' . $creationDate . ')' : '') . ', ' . $noOfInstallments . ' installment(s)',
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Scheduled Date'] . $r)),
                'installment_amount' => (float) $sheet->getCell($colByHeader['Installment Amount'] . $r)->getValue(),
                'installment_no' => null,
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
