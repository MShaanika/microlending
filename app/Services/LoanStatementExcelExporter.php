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
    public static function build(array $loan, array $borrower, array $schedule, array $ledger, ?array $company, ?array $summary = null): Spreadsheet
    {
        $s = $summary ?? LoanStatementSummary::build($loan, $schedule, $ledger);
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Statement');

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(38);
        foreach (['C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', $company['company_name'] ?? 'Micro Lending System');
        ExcelBrandStyle::title($sheet, 'A1:F1');

        $sheet->setCellValue('A2', 'Statement of Account');
        $sheet->setCellValue('A3', 'Loan No: ' . $loan['loan_no']);
        $sheet->setCellValue('A4', 'Client: ' . $borrower['first_name'] . ' ' . $borrower['last_name']);
        $sheet->setCellValue('A5', 'Date: ' . date('Y-m-d'));

        // Loan summary: the same figures, labels and order as the PDF and the screen (LoanStatementSummary).
        $row = 7;
        $sheet->setCellValue("A{$row}", 'LOAN SUMMARY');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $lines = [[$s['principal_label'], $s['principal']]];
        if ($s['levy'] > 0) {
            $lines[] = [$s['levy_label'], $s['levy']];
        }
        if ($s['stamp'] > 0) {
            $lines[] = [$s['stamp_label'], $s['stamp']];
        }
        $lines[] = [$s['opening_label'], $s['opening_balance']];
        $lines[] = [$s['interest_label'], $s['interest']];
        if ($s['admin_fee'] > 0) {
            $lines[] = ['+ Admin fee', $s['admin_fee']];
        }
        $lines[] = ['Total payable', $s['total_payable']];
        if ($s['penalties'] > 0) {
            $lines[] = ['Penalties charged', $s['penalties']];
        }
        $lines[] = ['Paid', $s['paid']];
        $lines[] = ['Still to pay' . ($s['paid_out'] ? '' : ' (not paid out yet)'), $s['outstanding']];
        foreach ($lines as [$label, $amount]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("C{$row}", round((float) $amount, 2));
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
            $row++;
        }
        if ($s['disbursed_on']) {
            $sheet->setCellValue("A{$row}", 'Paid out on');
            $sheet->setCellValue("C{$row}", $s['disbursed_on']);
            $row++;
        }
        $sheet->setCellValue("A{$row}", 'Status');
        $sheet->setCellValue("C{$row}", $loan['loan_status'] ?? '');
        $row += 2;

        $sheet->setCellValue("A{$row}", 'AMORTIZATION SCHEDULE');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $headerRow = $row;
        $sheet->fromArray(['#', 'Due Date', 'Opening Principal', 'Principal', 'Interest', 'Penalty', 'Fees', 'Total Due', 'Paid', 'Closing Principal', 'Status'], null, "A{$headerRow}");
        ExcelBrandStyle::header($sheet, "A{$headerRow}:K{$headerRow}");
        $row++;

        $totals = ['principal_due' => 0.0, 'interest_due' => 0.0, 'penalty_due' => 0.0, 'fees_due' => 0.0];
        foreach ($schedule as $r) {
            $sheet->setCellValue("A{$row}", (int) $r['installment_no']);
            $sheet->setCellValue("B{$row}", $r['due_date']);
            $sheet->setCellValue("C{$row}", round((float) $r['opening_balance'], 2));
            $sheet->setCellValue("D{$row}", round((float) $r['principal_due'], 2));
            $sheet->setCellValue("E{$row}", round((float) $r['interest_due'], 2));
            $sheet->setCellValue("F{$row}", round((float) ($r['penalty_due'] ?? 0), 2));
            $sheet->setCellValue("G{$row}", round((float) ($r['fees_due'] ?? 0), 2));
            $sheet->setCellValue("H{$row}", round((float) $r['total_due'], 2));
            $sheet->setCellValue("I{$row}", round((float) $r['total_paid'], 2));
            $sheet->setCellValue("J{$row}", round((float) $r['closing_balance'], 2));
            $sheet->setCellValue("K{$row}", schedule_status_label($r));
            $sheet->getStyle("C{$row}:J{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
            ExcelBrandStyle::border($sheet, "A{$row}:K{$row}");
            foreach ($totals as $k => $v) {
                $totals[$k] = $v + (float) ($r[$k] ?? 0);
            }
            $row++;
        }

        // Totals: the money columns only -- the Opening/Closing Principal columns are balances, never added up.
        $sheet->setCellValue("B{$row}", 'Total');
        $sheet->setCellValue("D{$row}", round($totals['principal_due'], 2));
        $sheet->setCellValue("E{$row}", round($totals['interest_due'], 2));
        $sheet->setCellValue("F{$row}", round($totals['penalty_due'], 2));
        $sheet->setCellValue("G{$row}", round($totals['fees_due'], 2));
        $sheet->setCellValue("H{$row}", $s['schedule_due']);
        $sheet->setCellValue("I{$row}", $s['paid']);
        $sheet->getStyle("D{$row}:I{$row}")->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
        ExcelBrandStyle::totals($sheet, "A{$row}:K{$row}");
        $row++;
        $sheet->setCellValue("B{$row}", LoanStatementSummary::stillToPayLine($s));
        $sheet->getStyle("B{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->setCellValue("B{$row}", LoanStatementSummary::scheduleNote($s));
        $row++;

        $row += 2;
        $sheet->setCellValue("A{$row}", 'LOAN STATEMENT (TRANSACTION HISTORY)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        if (!empty($ledger['notice'])) {
            $sheet->setCellValue("A{$row}", $ledger['notice']);
            $row++;
        }

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
