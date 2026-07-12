<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Plain header-info + data-table export for a regulatory_reports row and
 * its regulatory_report_lines, matching LoanStatementExcelExporter's simple
 * table shape rather than AfsExcelExporter's formula-heavy layout -- this
 * is a submission snapshot, not a live-recalculating workbook.
 */
class RegulatoryReportExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';

    private array $report;
    private array $lines;

    public function __construct(array $report, array $lines)
    {
        $this->report = $report;
        $this->lines = $lines;
    }

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $sheet->getColumnDimension('A')->setWidth(28);
        foreach (range('B', 'L') as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }

        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A1', $this->report['report_name']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');

        $sheet->setCellValue('A2', 'Report No: ' . $this->report['report_no']);
        $sheet->setCellValue('A3', 'Period: ' . $this->report['period_start'] . ' to ' . $this->report['period_end']);
        $sheet->setCellValue('A4', 'Status: ' . $this->report['status']);
        $sheet->setCellValue('A5', 'Generated: ' . ($this->report['generated_at'] ?? ''));

        $row = 7;
        $sheet->fromArray(
            ['Category', 'Description', 'Gender', 'Salary Band', 'Loan Size Band', 'Loan Count',
             'Principal', 'Outstanding', 'Bad Debt', 'Recovery', 'NAMFISA Levy', 'Duty Stamp'],
            null,
            "A{$row}"
        );
        $sheet->getStyle("A{$row}:L{$row}")->getFont()->setBold(true);
        $row++;

        foreach ($this->lines as $line) {
            $sheet->setCellValue("A{$row}", $line['line_category']);
            $sheet->setCellValue("B{$row}", $line['line_description']);
            $sheet->setCellValue("C{$row}", $line['gender']);
            $sheet->setCellValue("D{$row}", $line['salary_band']);
            $sheet->setCellValue("E{$row}", $line['loan_size_band']);
            $sheet->setCellValue("F{$row}", (int) $line['loan_count']);
            $sheet->setCellValue("G{$row}", round((float) $line['principal_amount'], 2));
            $sheet->setCellValue("H{$row}", round((float) $line['outstanding_amount'], 2));
            $sheet->setCellValue("I{$row}", round((float) $line['bad_debt_amount'], 2));
            $sheet->setCellValue("J{$row}", round((float) $line['recovery_amount'], 2));
            $sheet->setCellValue("K{$row}", round((float) $line['levy_amount'], 2));
            $sheet->setCellValue("L{$row}", round((float) $line['duty_stamp_amount'], 2));
            $sheet->getStyle("G{$row}:L{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $row++;
        }

        $row++;
        $sheet->setCellValue("A{$row}", 'Totals');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->setCellValue("F{$row}", (int) $this->report['total_loans']);
        $sheet->setCellValue("G{$row}", round((float) $this->report['total_principal'], 2));
        $sheet->setCellValue("I{$row}", round((float) $this->report['total_bad_debts'], 2));
        $sheet->setCellValue("J{$row}", round((float) $this->report['total_recoveries'], 2));
        $sheet->setCellValue("K{$row}", round((float) $this->report['total_namfisa_levy'], 2));
        $sheet->setCellValue("L{$row}", round((float) $this->report['total_duty_stamp'], 2));
        $sheet->getStyle("F{$row}:L{$row}")->getFont()->setBold(true);
        $sheet->getStyle("G{$row}:L{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        return $spreadsheet;
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
