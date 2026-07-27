<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel export for the Annual Financial Statement Analysis report -- three
 * titled sub-tables (quarterly summary, bank accounts, fixed assets) plus a
 * signature block, mirroring the shape of the client's own manually
 * compiled workbook of the same name. $sections is the section-keyed array
 * QuarterlyReportController builds for the view (groupAfsSections()).
 */
class AfsReportExcelExporter
{
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
        $sheet->setTitle('AFS Summary');

        foreach (['A' => 26, 'B' => 18, 'C' => 18, 'D' => 22, 'E' => 20, 'F' => 18, 'G' => 24] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;
        $sheet->setCellValue("A{$row}", 'Business Name:');
        $sheet->setCellValue("B{$row}", $company['company_name'] ?? '');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->setCellValue("A{$row}", 'NAMFISA Reg. No.:');
        $sheet->setCellValue("B{$row}", $company['namfisa_license_no'] ?? '');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        $sheet->setCellValue("A{$row}", 'Annual Financial Statement Analysis:');
        $sheet->setCellValue("B{$row}", $this->report['report_period']);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row += 2;

        $row = $this->writeQuarterlySummary($sheet, $row);
        $row += 1;
        $row = $this->writeBankAccounts($sheet, $row);
        $row += 1;
        $row = $this->writeFixedAssets($sheet, $row);
        $row += 2;

        $this->writeSignatureBlock($sheet, $row, $company);

        $this->writeMonthlyDetail($spreadsheet);

        return $spreadsheet;
    }

    /**
     * A second sheet mirroring the client's own workbook's monthly grid:
     * one row per metric/category, one column per FY month, in the same
     * order MONTHLY_DETAIL rows were generated in (see
     * AfsReportGenerationService::monthlyDetail()).
     */
    private function writeMonthlyDetail(Spreadsheet $spreadsheet): void
    {
        $rows = $this->sections['MONTHLY_DETAIL'] ?? [];
        if (empty($rows)) {
            return;
        }

        $monthLabels = [];
        $pivot = [];
        foreach ($rows as $r) {
            if (!in_array($r['sub_label'], $monthLabels, true)) {
                $monthLabels[] = $r['sub_label'];
            }
            $pivot[$r['label']][$r['sub_label']] = (float) $r['amount_1'];
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Monthly Detail');
        $sheet->getColumnDimension('A')->setWidth(42);
        foreach (range('B', 'N') as $col) {
            $sheet->getColumnDimension($col)->setWidth(14);
        }

        $headers = array_merge(['Category'], $monthLabels, ['Total']);
        $sheet->fromArray($headers, null, 'A1');
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        ExcelBrandStyle::header($sheet, "A1:{$lastCol}1");

        $row = 2;
        foreach ($pivot as $label => $byMonth) {
            $sheet->setCellValue("A{$row}", $label);
            $col = 2;
            $total = 0.0;
            foreach ($monthLabels as $ml) {
                $value = round($byMonth[$ml] ?? 0, 2);
                $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                $this->amount($sheet, $coord, $value);
                $total += $value;
                $col++;
            }
            $totalCoord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $this->amount($sheet, $totalCoord, $total);
            $sheet->getStyle($totalCoord)->getFont()->setBold(true);
            ExcelBrandStyle::border($sheet, "A{$row}:{$lastCol}{$row}");
            $row++;
        }
    }

    private function writeQuarterlySummary($sheet, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Summarised Report Quarterly');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $headers = ['Quarter', 'Expenditure (NAD)', 'Interest Income (NAD)', 'Disbursed Loans - Capital (NAD)', 'Members Contribution (NAD)', 'NAMFISA Levies (NAD)', 'Total Bad Debt Written Off (NAD)'];
        $sheet->fromArray($headers, null, "A{$row}");
        ExcelBrandStyle::header($sheet, "A{$row}:G{$row}");
        $row++;

        foreach ($this->sections['QUARTERLY_SUMMARY'] as $r) {
            $isTotal = $r['label'] === 'Total';
            $sheet->setCellValue("A{$row}", $r['label']);
            $this->amount($sheet, "B{$row}", $r['amount_1']);
            $this->amount($sheet, "C{$row}", $r['amount_2']);
            $this->amount($sheet, "D{$row}", $r['amount_3']);
            $this->amount($sheet, "E{$row}", $r['amount_6']);
            $this->amount($sheet, "F{$row}", $r['amount_4']);
            $this->amount($sheet, "G{$row}", $r['amount_5']);
            if ($isTotal) {
                ExcelBrandStyle::totals($sheet, "A{$row}:G{$row}");
            } else {
                ExcelBrandStyle::border($sheet, "A{$row}:G{$row}");
            }
            $row++;
        }

        return $row;
    }

    private function writeBankAccounts($sheet, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Bank Accounts');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->fromArray(['Account', 'Account No.', 'Balance (NAD)'], null, "A{$row}");
        ExcelBrandStyle::header($sheet, "A{$row}:C{$row}");
        $row++;

        $total = 0.0;
        foreach ($this->sections['BANK_ACCOUNTS'] as $r) {
            $sheet->setCellValue("A{$row}", $r['label']);
            $sheet->setCellValue("B{$row}", $r['sub_label']);
            $this->amount($sheet, "C{$row}", $r['amount_1']);
            ExcelBrandStyle::border($sheet, "A{$row}:C{$row}");
            $total += (float) $r['amount_1'];
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'Total');
        $this->amount($sheet, "C{$row}", $total);
        ExcelBrandStyle::totals($sheet, "A{$row}:C{$row}");
        $row++;

        return $row;
    }

    private function writeFixedAssets($sheet, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Assets');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $sheet->fromArray(['Description', 'Quantity', 'Unit Price', 'Total (NAD)'], null, "A{$row}");
        ExcelBrandStyle::header($sheet, "A{$row}:D{$row}");
        $row++;

        $total = 0.0;
        foreach ($this->sections['FIXED_ASSETS'] as $r) {
            $sheet->setCellValue("A{$row}", $r['label']);
            $sheet->setCellValue("B{$row}", (int) $r['amount_1']);
            $this->amount($sheet, "C{$row}", $r['amount_2']);
            $this->amount($sheet, "D{$row}", $r['amount_3']);
            ExcelBrandStyle::border($sheet, "A{$row}:D{$row}");
            $total += (float) $r['amount_3'];
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'Total');
        $this->amount($sheet, "D{$row}", $total);
        ExcelBrandStyle::totals($sheet, "A{$row}:D{$row}");
        $row++;

        return $row;
    }

    private function writeSignatureBlock($sheet, int $row, array $company): void
    {
        $sheet->setCellValue("A{$row}", 'Signature of Representative: ________________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Name of Representative: ________________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Registration No.: ' . ($company['namfisa_license_no'] ?? ''));
        $row += 2;
        $sheet->setCellValue("A{$row}", 'Date: ________________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Compiled by: ________________________');
        $row++;
        $sheet->setCellValue("A{$row}", 'Signature by Accountant: ________________________');
    }

    private function amount($sheet, string $coord, $value): void
    {
        $sheet->setCellValue($coord, round((float) $value, 2));
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(ExcelBrandStyle::numberFormat());
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
