<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Support\CollexiaCodes;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Builds an EnDo Batch v1.0 workbook matching Collexia's EnDOREQ template
 * exactly (sheet name "Batch", header row, one row per mandate, #END
 * marker) -- registers each mandate's FULL remaining installment count in
 * one row; Collexia then collects every period on its own, so this is a
 * one-time submission per mandate, not a monthly resubmission.
 */
class CollexiaEndoExporter
{
    private const HEADERS = [
        'PaymentFrequency', 'CollectionDay', 'Merchant Client No', 'Merchant System Contract No',
        'NextDateForCollection', 'InstallmentAmount', 'NoOfInstallments', 'NoOfDaysTracking',
        'Client Name', 'IDType', 'IDNumber', 'Client Bank Account Number', 'Client Account Type',
        'Client Bank', 'Cellphone No',
    ];

    /**
     * @param array $mandates Rows from DebitOrder::unregistered()
     */
    public static function build(array $mandates): Spreadsheet
    {
        $debitOrders = new DebitOrder();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Batch');

        $sheet->setCellValueExplicit('A1', 'EnDo', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B1', '1.0', DataType::TYPE_STRING);

        $sheet->fromArray(self::HEADERS, null, 'A2');
        $sheet->getStyle('A2:O2')->getFont()->setBold(true);

        $row = 3;
        foreach ($mandates as $m) {
            $loanId = (int) $m['loan_id'];
            $noOfInstallments = max(1, $debitOrders->remainingInstallments($loanId));
            $nextDate = $debitOrders->nextCollectionDate($loanId) ?: $m['start_date'];

            $sheet->setCellValueExplicit("A{$row}", CollexiaCodes::PAYMENT_FREQUENCY_MONTHLY, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("B{$row}", CollexiaCodes::collectionDayCode((int) $m['debit_day']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", (string) $m['borrower_id'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", (string) $m['merchant_system_contract_no'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", ExcelDate::PHPToExcel(new \DateTimeImmutable($nextDate)), DataType::TYPE_NUMERIC);
            $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->setCellValueExplicit("F{$row}", (float) $m['debit_amount'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("G{$row}", $noOfInstallments, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("H{$row}", (int) $m['no_of_days_tracking'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("I{$row}", substr(trim($m['borrower_name']), 0, 35), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("J{$row}", (int) ($m['id_type'] ?? 5), DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("K{$row}", (string) ($m['id_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("L{$row}", (string) $m['account_number'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("M{$row}", (int) ($m['account_type'] ?? 1), DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("N{$row}", (string) ($m['bank_code'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("O{$row}", substr((string) ($m['phone'] ?? ''), 0, 10), DataType::TYPE_STRING);
            $row++;
        }

        $sheet->setCellValue("A{$row}", '#END');
        $sheet->setCellValueExplicit("B{$row}", count($mandates), DataType::TYPE_NUMERIC);

        foreach (range('A', 'O') as $col) {
            $sheet->getColumnDimension($col)->setWidth(18);
        }

        return $spreadsheet;
    }
}
