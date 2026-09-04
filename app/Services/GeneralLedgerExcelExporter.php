<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Single-account chronological ledger with running balance -- mirrors the
 * on-screen General Ledger report (opening balance, lines, closing balance).
 */
class GeneralLedgerExcelExporter
{
    public static function build(array $account, array $ledger, string $fromDate, string $toDate): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('General Ledger');

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(28);
        foreach (['D', 'E', 'F'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'GENERAL LEDGER: ' . $account['account_code'] . ' - ' . $account['account_name']);
        ExcelBrandStyle::title($sheet, 'A1:F1');
        $sheet->setCellValue('A2', 'Period: ' . $fromDate . ' to ' . $toDate);

        $row = 4;
        $sheet->fromArray(['Date', 'Reference / Description', 'Category / Account', 'Debit', 'Credit', 'Balance'], null, "A{$row}");
        ExcelBrandStyle::header($sheet, "A{$row}:F{$row}");
        $row++;

        $sheet->setCellValue("B{$row}", 'Opening Balance');
        $sheet->setCellValue("F{$row}", round($ledger['opening_balance'], 2));
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
        ExcelBrandStyle::totals($sheet, "A{$row}:F{$row}");
        $row++;

        foreach ($ledger['lines'] as $line) {
            $sheet->setCellValue("A{$row}", date('Y-m-d', strtotime($line['journal_date'])));
            $desc = $line['journal_no'] . ($line['reference_no'] ? ' (' . $line['reference_no'] . ')' : '');
            $sheet->setCellValue("B{$row}", $desc);
            $sheet->setCellValue("C{$row}", $line['contra_accounts'] ?? '');
            $sheet->setCellValue("D{$row}", round((float) $line['debit'], 2));
            $sheet->setCellValue("E{$row}", round((float) $line['credit'], 2));
            $sheet->setCellValue("F{$row}", round((float) $line['running_balance'], 2));
            $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
            ExcelBrandStyle::border($sheet, "A{$row}:F{$row}");
            $row++;
        }

        $sheet->setCellValue("B{$row}", 'Closing Balance');
        $sheet->setCellValue("F{$row}", round($ledger['closing_balance'], 2));
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
        ExcelBrandStyle::totals($sheet, "A{$row}:F{$row}");

        return $spreadsheet;
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
