<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';
    private const FILL_GRAY = 'D8D8D8';

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

        foreach (['A' => 26, 'B' => 18, 'C' => 18, 'D' => 22, 'E' => 18, 'F' => 20] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;
        $sheet->setCellValue("A{$row}", 'Business Name:');
        $sheet->setCellValue("B{$row}", $company['company_name'] ?? '');
        $this->style($sheet, "A{$row}", true, null, false);
        $row++;
        $sheet->setCellValue("A{$row}", 'NAMFISA Reg. No.:');
        $sheet->setCellValue("B{$row}", $company['namfisa_license_no'] ?? '');
        $this->style($sheet, "A{$row}", true, null, false);
        $row++;
        $sheet->setCellValue("A{$row}", 'Annual Financial Statement Analysis:');
        $sheet->setCellValue("B{$row}", $this->report['report_period']);
        $this->style($sheet, "A{$row}", true, null, false);
        $row += 2;

        $row = $this->writeQuarterlySummary($sheet, $row);
        $row += 1;
        $row = $this->writeBankAccounts($sheet, $row);
        $row += 1;
        $row = $this->writeFixedAssets($sheet, $row);
        $row += 2;

        $this->writeSignatureBlock($sheet, $row, $company);

        return $spreadsheet;
    }

    private function writeQuarterlySummary($sheet, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Summarised Report Quarterly');
        $this->style($sheet, "A{$row}", true, null, false);
        $row++;

        $headers = ['Quarter', 'Expenditure (NAD)', 'Interest Income (NAD)', 'Disbursed Loans - Capital (NAD)', 'NAMFISA Levies (NAD)', 'Total Bad Debt Written Off (NAD)'];
        $sheet->fromArray($headers, null, "A{$row}");
        $this->style($sheet, "A{$row}:F{$row}", true, self::FILL_GRAY, true);
        $row++;

        foreach ($this->sections['QUARTERLY_SUMMARY'] as $r) {
            $isTotal = $r['label'] === 'Total';
            $sheet->setCellValue("A{$row}", $r['label']);
            $this->amount($sheet, "B{$row}", $r['amount_1']);
            $this->amount($sheet, "C{$row}", $r['amount_2']);
            $this->amount($sheet, "D{$row}", $r['amount_3']);
            $this->amount($sheet, "E{$row}", $r['amount_4']);
            $this->amount($sheet, "F{$row}", $r['amount_5']);
            $this->style($sheet, "A{$row}:F{$row}", $isTotal, $isTotal ? self::FILL_GRAY : null, true);
            $row++;
        }

        return $row;
    }

    private function writeBankAccounts($sheet, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Bank Accounts');
        $this->style($sheet, "A{$row}", true, null, false);
        $row++;

        $sheet->fromArray(['Account', 'Account No.', 'Balance (NAD)'], null, "A{$row}");
        $this->style($sheet, "A{$row}:C{$row}", true, self::FILL_GRAY, true);
        $row++;

        $total = 0.0;
        foreach ($this->sections['BANK_ACCOUNTS'] as $r) {
            $sheet->setCellValue("A{$row}", $r['label']);
            $sheet->setCellValue("B{$row}", $r['sub_label']);
            $this->amount($sheet, "C{$row}", $r['amount_1']);
            $this->style($sheet, "A{$row}:C{$row}", false, null, true);
            $total += (float) $r['amount_1'];
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'Total');
        $this->amount($sheet, "C{$row}", $total);
        $this->style($sheet, "A{$row}:C{$row}", true, self::FILL_GRAY, true);
        $row++;

        return $row;
    }

    private function writeFixedAssets($sheet, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Assets');
        $this->style($sheet, "A{$row}", true, null, false);
        $row++;

        $sheet->fromArray(['Description', 'Quantity', 'Unit Price', 'Total (NAD)'], null, "A{$row}");
        $this->style($sheet, "A{$row}:D{$row}", true, self::FILL_GRAY, true);
        $row++;

        $total = 0.0;
        foreach ($this->sections['FIXED_ASSETS'] as $r) {
            $sheet->setCellValue("A{$row}", $r['label']);
            $sheet->setCellValue("B{$row}", (int) $r['amount_1']);
            $this->amount($sheet, "C{$row}", $r['amount_2']);
            $this->amount($sheet, "D{$row}", $r['amount_3']);
            $this->style($sheet, "A{$row}:D{$row}", false, null, true);
            $total += (float) $r['amount_3'];
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'Total');
        $this->amount($sheet, "D{$row}", $total);
        $this->style($sheet, "A{$row}:D{$row}", true, self::FILL_GRAY, true);
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

    private function style($sheet, string $range, bool $bold, ?string $fill, bool $border): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold($bold);
        if ($fill !== null) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
        if ($border) {
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
    }

    private function amount($sheet, string $coord, $value): void
    {
        $sheet->setCellValue($coord, round((float) $value, 2));
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
