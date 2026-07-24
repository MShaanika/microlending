<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel export for the MLR Summarised Management Report -- one sheet, header
 * block, then the same 8 titled sub-tables as the on-screen view, in the
 * same order, ending with a signature block matching the real NAMFISA
 * filing's footer. $sections is the section-keyed array QuarterlyReportController
 * already builds for the view (groupMlrSections()).
 */
class MlrReportExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';

    private array $report;
    private array $sections;

    public function __construct(array $report, array $sections)
    {
        $this->report = $report;
        $this->sections = $sections;
    }

    public function build(): Spreadsheet
    {
        $company = (new Company())->primary() ?: [];

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('MLR Summary');

        $sheet->getColumnDimension('A')->setWidth(24);
        foreach (range('B', 'E') as $col) {
            $sheet->getColumnDimension($col)->setWidth(18);
        }

        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'MLR SUMMARISED MANAGEMENT REPORT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');

        $row = 2;
        $sheet->setCellValue("A{$row}", 'Business Name: ' . ($company['company_name'] ?? ''));
        $row++;
        $sheet->setCellValue("A{$row}", 'NAMFISA Reg. No.: ' . ($company['namfisa_license_no'] ?? ''));
        $row++;
        $sheet->setCellValue("A{$row}", 'Report No: ' . $this->report['report_no']);
        $row++;
        $sheet->setCellValue("A{$row}", 'Period: ' . $this->report['report_period']);
        $row++;
        $sheet->setCellValue("A{$row}", 'Status: ' . $this->report['status']);
        $row += 2;

        $row = $this->writeMonthlyTable($sheet, $row, '1. Total Loans Disbursed',
            ['Month', 'Capital (NAD)', 'Interest (NAD)', 'Total (NAD)', 'No.'],
            $this->sections['DISBURSED'], ['capital_amount', 'interest_amount', 'total_amount', 'loan_count']);

        $row = $this->writeLabelTable($sheet, $row, '2. Break-down by Gender',
            ['Gender', 'Amount (NAD)', 'No.'], $this->sections['GENDER']);

        $row = $this->writeLabelTable($sheet, $row, '3. Break-down by Size',
            ['Band', 'Amount (NAD)', 'No.'], $this->sections['SIZE']);

        $sheet->setCellValue("A{$row}", '4. Loan Book Balance as at End of Quarter (Including Interest)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(['Amount (NAD)', 'No.'], null, "A{$row}");
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $row++;
        $bb = $this->sections['BOOK_BALANCE'][0] ?? ['total_amount' => 0, 'loan_count' => 0];
        $sheet->setCellValue("A{$row}", round((float) $bb['total_amount'], 2));
        $sheet->getStyle("A{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $sheet->setCellValue("B{$row}", (int) $bb['loan_count']);
        $row += 2;

        $row = $this->writeMonthlyTable($sheet, $row, '5. Total Loans Written Off (Bad Debts)',
            ['Month', 'Capital (NAD)', 'Interest (NAD)', 'Total (NAD)', 'No.'],
            $this->sections['WRITTEN_OFF'], ['capital_amount', 'interest_amount', 'total_amount', 'loan_count']);

        $row = $this->writeMonthlyTable($sheet, $row, '6. Expenses',
            ['Month', 'Amount (NAD)'], $this->sections['EXPENSES'], ['total_amount']);

        $row = $this->writeMonthlyTable($sheet, $row, '7. Quarterly Interest Income - Segment',
            ['Month', 'Amount (NAD)'], $this->sections['INTEREST_INCOME'], ['total_amount']);

        $sheet->setCellValue("A{$row}", '8. Levies Payable to NAMFISA (Less Bad Debts)');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->fromArray(['Month', 'Levy', 'Less: Bad Debts', 'Net Payable'], null, "A{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $row++;
        $totalLevy = 0.0;
        $totalBadDebts = 0.0;
        foreach ($this->sections['LEVY'] as $r) {
            $sheet->setCellValue("A{$row}", $r['month_label']);
            $sheet->setCellValue("B{$row}", round((float) $r['total_amount'], 2));
            $sheet->setCellValue("C{$row}", round((float) $r['capital_amount'], 2));
            $sheet->setCellValue("D{$row}", round((float) $r['total_amount'] - (float) $r['capital_amount'], 2));
            $sheet->getStyle("B{$row}:D{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $totalLevy += (float) $r['total_amount'];
            $totalBadDebts += (float) $r['capital_amount'];
            $row++;
        }
        $sheet->setCellValue("A{$row}", 'Total');
        $sheet->setCellValue("B{$row}", round($totalLevy, 2));
        $sheet->setCellValue("C{$row}", round($totalBadDebts, 2));
        $sheet->setCellValue("D{$row}", round($totalLevy - $totalBadDebts, 2));
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("B{$row}:D{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $row += 3;

        $sheet->setCellValue("A{$row}", 'Signature: ________________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Name of Representative: ________________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'PRINCIPAL OFFICER');
        $row++;
        $sheet->setCellValue("A{$row}", 'Date: ________________________');

        return $spreadsheet;
    }

    private function writeMonthlyTable($sheet, int $row, string $title, array $headers, array $rows, array $amountKeys): int
    {
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $lastCol = chr(ord('A') + count($headers) - 1);
        $sheet->fromArray($headers, null, "A{$row}");
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
        $row++;

        $totals = array_fill_keys($amountKeys, 0.0);
        foreach ($rows as $r) {
            $col = 'A';
            $sheet->setCellValue("{$col}{$row}", $r['month_label']);
            foreach ($amountKeys as $key) {
                $col++;
                $value = $key === 'loan_count' ? (int) $r[$key] : round((float) $r[$key], 2);
                $sheet->setCellValue("{$col}{$row}", $value);
                if ($key !== 'loan_count') {
                    $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
                }
                $totals[$key] += (float) $r[$key];
            }
            $row++;
        }

        $col = 'A';
        $sheet->setCellValue("{$col}{$row}", 'Total');
        foreach ($amountKeys as $key) {
            $col++;
            $value = $key === 'loan_count' ? (int) $totals[$key] : round($totals[$key], 2);
            $sheet->setCellValue("{$col}{$row}", $value);
        }
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
        $row += 2;

        return $row;
    }

    private function writeLabelTable($sheet, int $row, string $title, array $headers, array $rows): int
    {
        $sheet->setCellValue("A{$row}", $title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->fromArray($headers, null, "A{$row}");
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        $row++;

        $totalAmount = 0.0;
        $totalCount = 0;
        foreach ($rows as $r) {
            $sheet->setCellValue("A{$row}", $r['label']);
            $sheet->setCellValue("B{$row}", round((float) $r['total_amount'], 2));
            $sheet->setCellValue("C{$row}", (int) $r['loan_count']);
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $totalAmount += (float) $r['total_amount'];
            $totalCount += (int) $r['loan_count'];
            $row++;
        }
        $sheet->setCellValue("A{$row}", 'Total');
        $sheet->setCellValue("B{$row}", round($totalAmount, 2));
        $sheet->setCellValue("C{$row}", $totalCount);
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        $row += 2;

        return $row;
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
