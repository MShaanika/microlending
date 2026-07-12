<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel version of the loan statement of account -- same schedule +
 * transaction ledger content as the printable HTML statement, for staff
 * or borrowers who want a spreadsheet instead of a PDF.
 */
class LoanStatementExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';

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
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');

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
        $sheet->getStyle("A{$headerRow}:G{$headerRow}")->getFont()->setBold(true);
        $row++;

        foreach ($schedule as $s) {
            $sheet->setCellValue("A{$row}", (int) $s['installment_no']);
            $sheet->setCellValue("B{$row}", $s['due_date']);
            $sheet->setCellValue("C{$row}", round((float) $s['principal_due'], 2));
            $sheet->setCellValue("D{$row}", round((float) $s['interest_due'], 2));
            $sheet->setCellValue("E{$row}", round((float) $s['total_due'], 2));
            $sheet->setCellValue("F{$row}", round((float) $s['total_paid'], 2));
            $sheet->setCellValue("G{$row}", round((float) $s['total_due'] - (float) $s['total_paid'], 2));
            $sheet->getStyle("C{$row}:G{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $row++;
        }

        $row += 2;
        $sheet->setCellValue("A{$row}", 'TRANSACTION HISTORY');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $ledgerHeaderRow = $row;
        $sheet->fromArray(['Date', 'Type', 'Description', 'Charged', 'Paid', 'Balance'], null, "A{$ledgerHeaderRow}");
        $sheet->getStyle("A{$ledgerHeaderRow}:F{$ledgerHeaderRow}")->getFont()->setBold(true);
        $row++;

        foreach ($ledger['events'] as $event) {
            $sheet->setCellValue("A{$row}", $event['date'] ?: '-');
            $sheet->setCellValue("B{$row}", $event['type']);
            $sheet->setCellValue("C{$row}", $event['description']);
            $sheet->setCellValue("D{$row}", round($event['debit'], 2));
            $sheet->setCellValue("E{$row}", round($event['credit'], 2));
            $sheet->setCellValue("F{$row}", round($event['balance'], 2));
            $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $row++;
        }

        $sheet->setCellValue("C{$row}", 'Closing Balance');
        $sheet->setCellValue("F{$row}", round($ledger['closing_balance'], 2));
        $sheet->getStyle("C{$row}:F{$row}")->getFont()->setBold(true);
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        return $spreadsheet;
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
