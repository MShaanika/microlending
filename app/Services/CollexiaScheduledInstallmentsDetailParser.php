<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parses Collexia's "Scheduled Installments With Detail Selection" report
 * export (0006) -- a richer version of the plain Scheduled Installments
 * report (broad status snapshot across every installment, due or not) that
 * also carries a "Contract Reference" column and, for completed
 * installments, Payment Date/Payment Amount.
 *
 * Deliberately visibility-only, same as the plain Scheduled Installments
 * report -- it does not post a Payment even though Payment Date/Amount are
 * captured here, because this report lists EVERY installment regardless of
 * status (not just ones that actually succeeded), so there's no reliable
 * signal here for "this is a genuinely new collection, safe to post" the
 * way the Successful Transactions reports provide. Posting/reconciliation
 * stays owned by the Successful-family reports and the Download Payments
 * API, per this project's existing convention.
 */
class CollexiaScheduledInstallmentsDetailParser
{
    private const REQUIRED_HEADERS = [
        'Merchant System Contract No', 'Contract Reference', 'Installment Status', 'Scheduled Date',
        'Installment Amount', 'No',
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

        $sheet = CollexiaReportReader::findSheet($spreadsheet, 'Scheduled Installments With Detail Selection');
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
                'installment_status' => trim((string) $sheet->getCell($colByHeader['Installment Status'] . $r)->getValue()),
                'scheduled_date' => CollexiaReportReader::readDate($sheet->getCell($colByHeader['Scheduled Date'] . $r)),
                'installment_amount' => (float) $sheet->getCell($colByHeader['Installment Amount'] . $r)->getValue(),
                'installment_no' => (int) $sheet->getCell($colByHeader['No'] . $r)->getValue(),
                'payment_date' => isset($colByHeader['Payment Date']) ? CollexiaReportReader::readDate($sheet->getCell($colByHeader['Payment Date'] . $r)) : null,
                'payment_amount' => isset($colByHeader['Payment Amount']) ? (float) $sheet->getCell($colByHeader['Payment Amount'] . $r)->getValue() : null,
            ];
        }

        return ['rows' => $rows, 'errors' => []];
    }
}
