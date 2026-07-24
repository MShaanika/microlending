<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Single-account chronological ledger with running balance -- mirrors the
 * on-screen General Ledger report (opening balance, lines, closing balance).
 */
class GeneralLedgerExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';

    public static function build(array $account, array $ledger, string $fromDate, string $toDate): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('General Ledger');

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(40);
        foreach (['C', 'D', 'E'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }

        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'GENERAL LEDGER: ' . $account['account_code'] . ' - ' . $account['account_name']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');
        $sheet->setCellValue('A2', 'Period: ' . $fromDate . ' to ' . $toDate);

        $row = 4;
        $sheet->fromArray(['Date', 'Reference / Description', 'Debit', 'Credit', 'Balance'], null, "A{$row}");
        $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->setCellValue("B{$row}", 'Opening Balance');
        $sheet->setCellValue("E{$row}", round($ledger['opening_balance'], 2));
        $sheet->getStyle("B{$row}:E{$row}")->getFont()->setBold(true);
        $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $row++;

        foreach ($ledger['lines'] as $line) {
            $sheet->setCellValue("A{$row}", date('Y-m-d', strtotime($line['journal_date'])));
            $desc = $line['journal_no'] . ($line['reference_no'] ? ' (' . $line['reference_no'] . ')' : '');
            $sheet->setCellValue("B{$row}", $desc);
            $sheet->setCellValue("C{$row}", round((float) $line['debit'], 2));
            $sheet->setCellValue("D{$row}", round((float) $line['credit'], 2));
            $sheet->setCellValue("E{$row}", round((float) $line['running_balance'], 2));
            $sheet->getStyle("C{$row}:E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $row++;
        }

        $sheet->setCellValue("B{$row}", 'Closing Balance');
        $sheet->setCellValue("E{$row}", round($ledger['closing_balance'], 2));
        $sheet->getStyle("B{$row}:E{$row}")->getFont()->setBold(true);
        $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        return $spreadsheet;
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
