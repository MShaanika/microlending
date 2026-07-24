<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel version of the loan statement of account -- same schedule +
 * transaction ledger content as the printable HTML statement, for staff
 * or borrowers who want a spreadsheet instead of a PDF.
 */
class LoanStatementExcelExporter
{
    public static function build(array $loan, array $borrower, array $schedule, array $ledger, ?array $company): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Statement');

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(38);
        foreach (['C', 'D', 'E', 'F'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', $company['company_name'] ?? 'Micro Lending System');
        ExcelBrandStyle::title($sheet, 'A1:F1');

        $sheet->setCellValue('A2', 'Statement of Account');
        $sheet->setCellValue('A3', 'Loan No: ' . $loan['loan_no']);
        $sheet->setCellValue('A4', 'Borrower: ' . $borrower['first_name'] . ' ' . $borrower['last_name']);
        $sheet->setCellValue('A5', 'Date: ' . date('Y-m-d'));

        $row = 7;
        $sheet->setCellValue("A{$row}", 'AMORTIZATION SCHEDULE');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $headerRow = $row;
        $sheet->fromArray(['#', 'Due Date', 'Principal', 'Interest', 'Total Due', 'Paid', 'Balance'], null, "A{$headerRow}");
        ExcelBrandStyle::header($sheet, "A{$headerRow}:G{$headerRow}");
        $row++;

        foreach ($schedule as $s) {
            $sheet->setCellValue("A{$row}", (int) $s['installment_no']);
            $sheet->setCellValue("B{$row}", $s['due_date']);
            $sheet->setCellValue("C{$row}", round((float) $s['principal_due'], 2));
            $sheet->setCellValue("D{$row}", round((float) $s['interest_due'], 2));
            $sheet->setCellValue("E{$row}", round((float) $s['total_due'], 2));
            $sheet->setCellValue("F{$row}", round((float) $s['total_paid'], 2));
            $sheet->setCellValue("G{$row}", round((float) $s['total_due'] - (float) $s['total_paid'], 2));
            $sheet->getStyle("C{$row}:G{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
            ExcelBrandStyle::border($sheet, "A{$row}:G{$row}");
            $row++;
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'LOAN STATEMENT (TRANSACTION HISTORY)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $ledgerHeaderRow = $row;
        $sheet->fromArray(['Date', 'Type', 'Description', 'Debit', 'Credit', 'Balance'], null, "A{$ledgerHeaderRow}");
        ExcelBrandStyle::header($sheet, "A{$ledgerHeaderRow}:F{$ledgerHeaderRow}");
        $row++;

        foreach ($ledger['events'] as $event) {
            $sheet->setCellValue("A{$row}", $event['date'] ?: '-');
            $sheet->setCellValue("B{$row}", $event['type']);
            $sheet->setCellValue("C{$row}", $event['description']);
            $sheet->setCellValue("D{$row}", round($event['debit'], 2));
            $sheet->setCellValue("E{$row}", round($event['credit'], 2));
            $sheet->setCellValue("F{$row}", round($event['balance'], 2));
            $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
            ExcelBrandStyle::border($sheet, "A{$row}:F{$row}");
            $row++;
        }

        $sheet->setCellValue("C{$row}", 'Closing Balance');
        $sheet->setCellValue("F{$row}", round($ledger['closing_balance'], 2));
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
        ExcelBrandStyle::totals($sheet, "C{$row}:F{$row}");

        return $spreadsheet;
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
