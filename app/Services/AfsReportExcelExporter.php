<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel export for the Annual Financial Statement Analysis report -- one
 * sheet, laid out and colored to match the client's own real workbook
 * exactly (gray D8D8D8 header/total rows, orange FF9900 monthly data
 * cells): the monthly grid (Month + Total Disbursed/Interest Income/every
 * expense category, one row per FY month), the quarterly summary, bank
 * accounts, fixed assets, and a signature block. $sections is the
 * section-keyed array QuarterlyReportController builds for the view
 * (groupAfsSections()).
 *
 * One deliberate deviation from the source file: its header row has
 * textRotation=180 (literally upside-down), which reads as an artifact of
 * whatever tool/version last touched that file rather than an intentional
 * choice -- wrapped, non-rotated headers with a tall row are used instead
 * so the column names stay legible.
 */
class AfsReportExcelExporter
{
    private const FILL_HEADER = 'D8D8D8';
    private const FILL_DATA = 'FF9900';
    private const NUMBER_FORMAT = '#,##0.00';

    /**
     * (MONTHLY_DETAIL label => target column letter), in the exact column
     * order of the client's real workbook (B onward; A is Month). The
     * label is either a MONTHLY_DETAIL row label produced by
     * AfsReportGenerationService::monthlyDetail(), or the literal string
     * '__NOT_TRACKED__' for a column the system has no data source for
     * yet (Interest Received from Investments) -- rendered as a
     * structural placeholder, always 0.00, not fabricated data.
     */
    private const COLUMN_MAP = [
        'Total Disbursed Amount' => 'Total Disbursed Amount',
        'Interest Income' => 'Interest Income',
        '__NOT_TRACKED__' => 'Interest Received from Investments',
        'Fringe Benefits / Employee Benefits' => 'Fringe Benefits / Employee Benefits',
        'Bank Charges' => 'Bank Charges',
        'Debit Interest' => 'Debit Interest',
        'Entertainment, Employee Benefits, Travel & Food Expenses' => 'Entertainment, Employee Benefits, Travel & Food Expenses',
        'Accounting and Bookkeeping Fees' => 'Accounting and Bookkeeping Fees',
        'Stationery' => 'Stationery',
        'Document Warehouse Fees' => 'Document Warehouse Fees',
        'Building Maintenance' => 'Building Maintenance',
        'Marketing, Printing and Promotional Materials' => 'Marketing, Printing and Promotional Materials',
        'Courier and Postage' => 'Courier and Postage',
        'Salary Expenses' => 'Salaries / Wages',
        'Social Security Expenses' => 'Social Security Expenses',
        'Subscriptions and Annuities (Real Pay, Collexia & Compuscan)' => 'Subscriptions and Annuities (Real Pay, Collexia & Compuscan)',
        'Administration Cost' => 'Administration Cost',
        'NAMFISA Licence and Consulting Fees' => 'NAMFISA Licence and Consulting Fees',
        'Internet and Telephone Expenses' => 'Internet and Telephone Expenses',
        'Fuel and Car Maintenance' => 'Fuel and Car Maintenance',
        'Office Supplies' => 'Office Supplies',
        'Legal and Consulting Fees' => 'Legal and Consulting Fees',
        'Medical Expenses' => 'Medical Expenses',
        'Municipal Expenses (Water & Electricity)' => 'Municipal Expenses (Water & Electricity)',
        'Technology and System Maintenance Fees' => 'Technology and System Maintenance Fees',
        'Bad Debts' => 'Bad Debts',
        'Household Expenses' => 'Household Expenses',
        'Rent Payment' => 'Rent Payment',
        'Furniture' => 'Furniture',
        'Clothing' => 'Clothing',
        'Deposit on Capital Goods (Movable Assets)' => 'Deposit on Capital Goods (Movable Assets)',
        'Tax Paid' => 'Tax Paid',
        'Insurance' => 'Insurance',
        'Car Payment (Asset Finance)' => 'Car Payment (Asset Finance)',
        'Livestock' => 'Livestock',
        'VAT Paid or Refund' => 'VAT Paid or Refund',
    ];

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
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('AFS');

        $row = 1;
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

        $monthlyGridHeaderRow = $row;
        $row = $this->writeMonthlyGrid($sheet, $row);
        $row += 2;
        $row = $this->writeQuarterlySummary($sheet, $row);
        $row += 2;
        $row = $this->writeBankAccounts($sheet, $row);
        $row += 1;
        $row = $this->writeFixedAssets($sheet, $row);
        $row += 2;

        $this->writeSignatureBlock($sheet, $row, $company);

        $sheet->getColumnDimension('A')->setWidth(14);
        for ($i = 2; $i <= 2 + count(self::COLUMN_MAP); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(15);
        }
        $sheet->freezePane('B' . ($monthlyGridHeaderRow + 1));

        return $spreadsheet;
    }

    /**
     * Month | Total Disbursed Amount | Interest Income | ... | VAT Paid or
     * Refund, one row per FY month plus a TOTAL row -- same column order,
     * same gray header/orange data/gray total styling as the client's
     * real workbook. Pivoted from the MONTHLY_DETAIL section (one row per
     * month x metric) rather than a schema change.
     */
    private function writeMonthlyGrid($sheet, int $row): int
    {
        $rows = $this->sections['MONTHLY_DETAIL'] ?? [];
        if (empty($rows)) {
            return $row;
        }

        $monthLabels = [];
        $pivot = [];
        foreach ($rows as $r) {
            if (!in_array($r['sub_label'], $monthLabels, true)) {
                $monthLabels[] = $r['sub_label'];
            }
            $pivot[$r['label']][$r['sub_label']] = (float) $r['amount_1'];
        }

        $columns = self::COLUMN_MAP;
        $lastColIndex = 1 + count($columns);
        $lastCol = Coordinate::stringFromColumnIndex($lastColIndex);

        $headerRow = $row;
        $sheet->setCellValue("A{$headerRow}", 'Month');
        $col = 2;
        foreach ($columns as $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $headerRow, $header);
            $col++;
        }
        $this->fill($sheet, "A{$headerRow}:{$lastCol}{$headerRow}", self::FILL_HEADER, true, Border::BORDER_MEDIUM, null);
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getAlignment()->setWrapText(true)->setVertical('bottom');
        $sheet->getRowDimension($headerRow)->setRowHeight(60);
        $row++;

        $totals = array_fill(0, count($columns), 0.0);
        foreach ($monthLabels as $ml) {
            $sheet->setCellValue("A{$row}", $ml);
            $this->fill($sheet, "A{$row}", self::FILL_HEADER, false, Border::BORDER_THIN, null);

            $col = 2;
            $i = 0;
            foreach ($columns as $sourceLabel => $header) {
                $value = $sourceLabel === '__NOT_TRACKED__' ? 0.0 : round($pivot[$sourceLabel][$ml] ?? 0, 2);
                $coord = Coordinate::stringFromColumnIndex($col) . $row;
                $sheet->setCellValue($coord, $value);
                $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
                $this->fill($sheet, $coord, self::FILL_DATA, false, Border::BORDER_THIN, null);
                $totals[$i] += $value;
                $col++;
                $i++;
            }
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'TOTAL');
        $col = 2;
        foreach ($totals as $t) {
            $coord = Coordinate::stringFromColumnIndex($col) . $row;
            $sheet->setCellValue($coord, round($t, 2));
            $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $col++;
        }
        $this->fill($sheet, "A{$row}:{$lastCol}{$row}", self::FILL_HEADER, true, Border::BORDER_MEDIUM, Border::BORDER_MEDIUM);
        $row++;

        return $row;
    }

    /**
     * Same gray fill (D8D8D8) across the whole table -- header row and
     * every data/total row -- matching the client's own quarterly summary
     * styling exactly (not just the header).
     */
    private function writeQuarterlySummary($sheet, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Summarised Report Quarterly');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $headers = ['Quarter', 'Expenditure (NAD)', 'Interest Income (NAD)', 'Disbursed Loans - Capital (NAD)', 'Members Contribution (NAD)', 'NAMFISA Levies (NAD)', 'Total Bad Debt Written Off (NAD)'];
        $sheet->fromArray($headers, null, "A{$row}");
        $this->fill($sheet, "A{$row}:G{$row}", self::FILL_HEADER, true, Border::BORDER_THIN, null);
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
            $this->fill($sheet, "A{$row}:G{$row}", self::FILL_HEADER, $isTotal, Border::BORDER_THIN, Border::BORDER_THIN);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
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
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
    }

    private function fill($sheet, string $range, string $rgb, bool $bold, ?string $topBorder, ?string $bottomBorder): void
    {
        $style = $sheet->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
        $style->getFont()->setBold($bold);
        if ($topBorder) {
            $style->getBorders()->getTop()->setBorderStyle($topBorder);
        }
        if ($bottomBorder) {
            $style->getBorders()->getBottom()->setBorderStyle($bottomBorder);
        }
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
