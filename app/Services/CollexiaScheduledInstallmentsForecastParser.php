<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Scheduled installments forecast" report export
 * (0052) -- a forward-looking list of installments still due in the
 * requested date range. Carries NO contract reference or "Merchant System
 * Contract No" at all, only a "Merchant Client No" (the client-facing loan
 * reference) -- not a safe key to resolve a specific local mandate from (a
 * wrong guess would misattribute a forecasted amount to the wrong loan), so
 * this import never attempts to match a debit order at all: every row is
 * recorded with debit_order_id/loan_id left null and matched=0, purely for
 * visibility. This also never posts a Payment -- a forecast is not a
 * collection event, there's nothing to reconcile.
 */
class CollexiaScheduledInstallmentsForecastParser
{
    private const ANCHOR_HEADER = 'Merchant Client No';

    private const REQUIRED_HEADERS = [
        'Merchant Client No', 'Client Name', 'Installment Status', 'Scheduled Date', 'Installment Amount', 'Installment No',
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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Scheduled installments forecast');
        [$headerRow, $colByHeader, $error] = CollexiaReportReader::locateHeaders($sheet, self::REQUIRED_HEADERS, 10, self::ANCHOR_HEADER);
        if ($error !== null) {
            return ['rows' => [], 'errors' => [$error]];
        }

        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $clientNo = trim((string) $sheet->getCell($colByHeader['Merchant Client No'] . $r)->getValue());
            if ($clientNo === '') {
                continue;
            }

            $rows[] = [
                'merchant_client_no' => $clientNo,
                'client_name' => trim((string) $sheet->getCell($colByHeader['Client Name'] . $r)->getValue()),
                'installment_status' => trim((string) $sheet->getCell($colByHeader['Installment Status'] . $r)->getValue()),
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Scheduled Date'] . $r)),
                'installment_amount' => (float) $sheet->getCell($colByHeader['Installment Amount'] . $r)->getValue(),
                'installment_no' => (int) $sheet->getCell($colByHeader['Installment No'] . $r)->getValue(),
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
