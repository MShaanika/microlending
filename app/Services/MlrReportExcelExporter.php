<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel export for the MLR Summarised Management Report -- reproduces the
 * real NAMFISA filing's exact cell grid (rows 2-31, columns A-M), captured
 * cell-by-cell from the client's own historical submissions, rather than an
 * equivalent-but-differently-laid-out sheet. $sections is the section-keyed
 * array QuarterlyReportController already builds for the view
 * (groupMlrSections()).
 *
 * Three deliberate departures from a literal 1:1 dump of $sections, all
 * matching what the real filing actually shows despite the system tracking
 * finer-grained data internally:
 *  - "Quarterly Interest Income - Segment" is a single quarter total in the
 *    real filing (no monthly breakdown) -- the three months are summed for
 *    the H24 cell.
 *  - "Levies Payable ... (Less Bad Debts)" shows one "Amount (NAD)" column
 *    per month in the real filing, not separate Levy/Bad Debts/Net columns
 *    -- the net figure (levy minus that month's written-off capital) is
 *    written as the single value, matching the section's own title.
 *  - The size band table shows 5 rows; the top two bands the system tracks
 *    separately ("N$40,001 - N$50,000" and "Above N$50,000") are combined
 *    into the filing's single "N$40,001 - N$50,000+" row.
 */
class MlrReportExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';
    private const FILL_GRAY = 'D8D8D8';
    private const FILL_BLUE = 'DEEAF6';
    private const FILL_BLUE_DARK = 'BDD6EE';

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

        $widths = [
            'A' => 16.33, 'B' => 13.11, 'C' => 16.44, 'D' => 14.0, 'E' => 11.44, 'F' => 2.55,
            'G' => 17.66, 'H' => 16.11, 'I' => 18.44, 'J' => 0.66, 'K' => 16.0, 'L' => 15.66, 'M' => 25.33,
        ];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $periodStart = $this->report['period_start'];
        $quarter = (int) ceil(((int) date('n', strtotime($periodStart))) / 3);
        $year = date('Y', strtotime($periodStart));
        $endDate = date('d/m/Y', strtotime($this->report['period_end']));

        $this->writeHeaderBlock($sheet, $company, $quarter, $year, $endDate);
        $this->writeDisbursedTable($sheet);
        $this->writeGenderTable($sheet);
        $this->writeSizeTable($sheet);
        $this->writeBookBalance($sheet);
        $this->writeWrittenOffTable($sheet);
        $this->writeExpensesTable($sheet);
        $this->writeInterestIncome($sheet);
        $this->writeLevyTable($sheet);
        $this->writeSignatureBlock($sheet);

        return $spreadsheet;
    }

    private function writeHeaderBlock($sheet, array $company, int $quarter, string $year, string $endDate): void
    {
        $this->merge($sheet, 'B2:M2');
        $sheet->setCellValue('A2', 'Business Name:');
        $sheet->setCellValue('B2', $company['company_name'] ?? '');
        $this->style($sheet, 'A2', true, null, false);
        $this->style($sheet, 'B2:M2', true, null, true, Alignment::HORIZONTAL_CENTER);

        $this->merge($sheet, 'B3:M3');
        $sheet->setCellValue('A3', 'NAMFISA Reg. No.:');
        $sheet->setCellValue('B3', $company['namfisa_license_no'] ?? '');
        $this->style($sheet, 'A3', true, null, false);
        $this->style($sheet, 'B3:M3', true, null, true, Alignment::HORIZONTAL_CENTER);

        $this->merge($sheet, 'A4:M4');
        $sheet->setCellValue('A4', 'Summarised Management Report:Quarter ' . $quarter);
        $this->style($sheet, 'A4', true, null, false, Alignment::HORIZONTAL_CENTER);

        $this->merge($sheet, 'B5:M5');
        $sheet->setCellValue('A5', 'Quarter No:');
        $sheet->setCellValue('B5', $quarter);
        $this->style($sheet, 'A5', true, null, false);
        $this->style($sheet, 'B5:M5', true, null, true, Alignment::HORIZONTAL_CENTER);

        $this->merge($sheet, 'B6:M6');
        $sheet->setCellValue('A6', 'End Date:');
        $sheet->setCellValue('B6', $endDate);
        $this->style($sheet, 'A6', true, null, false);
        $this->style($sheet, 'B6:M6', true, null, true, Alignment::HORIZONTAL_CENTER);

        $this->merge($sheet, 'B7:M7');
        $sheet->setCellValue('A7', 'Year:');
        $sheet->setCellValue('B7', $year);
        $this->style($sheet, 'A7', true, null, false);
        $this->style($sheet, 'B7:M7', true, null, true, Alignment::HORIZONTAL_CENTER);
    }

    private function writeDisbursedTable($sheet): void
    {
        $this->merge($sheet, 'A9:E9');
        $sheet->setCellValue('A9', 'Total Loans Disbursed');
        $this->style($sheet, 'A9', true, null, false, Alignment::HORIZONTAL_LEFT);

        $this->rowValues($sheet, 'B10', ['Capital (NAD)', 'Interest (NAD)', 'Total (NAD)', 'No. (Quantity)']);
        $this->style($sheet, 'B10:E10', true, self::FILL_GRAY, true);

        $rows = array_values($this->sections['DISBURSED']);
        $totals = ['capital_amount' => 0.0, 'interest_amount' => 0.0, 'total_amount' => 0.0, 'loan_count' => 0];
        $row = 11;
        foreach ($rows as $r) {
            $sheet->setCellValue("A{$row}", $r['month_label']);
            $this->style($sheet, "A{$row}", true, self::FILL_GRAY, true);
            $this->amount($sheet, "B{$row}", $r['capital_amount']);
            $this->amount($sheet, "C{$row}", $r['interest_amount']);
            $this->amount($sheet, "D{$row}", $r['total_amount']);
            $this->intCell($sheet, "E{$row}", $r['loan_count']);
            $this->style($sheet, "B{$row}:E{$row}", false, null, true);
            foreach ($totals as $k => &$v) {
                $v += (float) $r[$k];
            }
            unset($v);
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'Total');
        $this->amount($sheet, "B{$row}", $totals['capital_amount']);
        $this->amount($sheet, "C{$row}", $totals['interest_amount']);
        $this->amount($sheet, "D{$row}", $totals['total_amount']);
        $this->intCell($sheet, "E{$row}", (int) $totals['loan_count']);
        $this->style($sheet, "A{$row}:E{$row}", true, self::FILL_GRAY, true);
    }

    private function writeGenderTable($sheet): void
    {
        $this->merge($sheet, 'G9:I9');
        $sheet->setCellValue('G9', 'Break-down by Gender');
        $this->style($sheet, 'G9', true, null, false, Alignment::HORIZONTAL_LEFT);

        $sheet->setCellValue('H10', 'Amount (NAD)');
        $sheet->setCellValue('I10', 'No. (Quantity)');
        $this->style($sheet, 'H10:I10', true, self::FILL_BLUE, true);

        $byLabel = [];
        foreach ($this->sections['GENDER'] as $r) {
            $byLabel[strtolower(trim((string) $r['label']))] = $r;
        }
        $empty = ['total_amount' => 0.0, 'loan_count' => 0];
        $male = $byLabel['male'] ?? $empty;
        $female = $byLabel['female'] ?? $empty;

        $sheet->setCellValue('G11', 'Male');
        $this->style($sheet, 'G11', false, self::FILL_BLUE, true);
        $this->amount($sheet, 'H11', $male['total_amount']);
        $this->intCell($sheet, 'I11', $male['loan_count']);
        $this->style($sheet, 'H11:I11', false, null, true);

        $sheet->setCellValue('G12', 'Female');
        $this->style($sheet, 'G12', false, self::FILL_BLUE, true);
        $this->amount($sheet, 'H12', $female['total_amount']);
        $this->intCell($sheet, 'I12', $female['loan_count']);
        $this->style($sheet, 'H12:I12', false, null, true);

        $totalAmount = (float) $male['total_amount'] + (float) $female['total_amount'];
        $totalCount = (int) $male['loan_count'] + (int) $female['loan_count'];
        $sheet->setCellValue('G13', 'Total');
        $this->amount($sheet, 'H13', $totalAmount);
        $this->intCell($sheet, 'I13', $totalCount);
        $this->style($sheet, 'G13:I13', true, self::FILL_BLUE_DARK, true);
    }

    private function writeSizeTable($sheet): void
    {
        $this->merge($sheet, 'K10:M10');
        $sheet->setCellValue('K10', 'Break-down by Size');
        $this->style($sheet, 'K10', true, null, false, Alignment::HORIZONTAL_LEFT);

        $sheet->setCellValue('L11', 'Amount');
        $sheet->setCellValue('M11', 'No. (Quantity)');
        $this->style($sheet, 'L11:M11', true, self::FILL_BLUE, true);

        $bands = array_values($this->sections['SIZE']);
        $combined = array_slice($bands, 0, 4);
        $tail1 = $bands[4] ?? ['total_amount' => 0.0, 'loan_count' => 0];
        $tail2 = $bands[5] ?? ['total_amount' => 0.0, 'loan_count' => 0];
        $combined[] = [
            'label' => 'N$40,001 - N$50,000+',
            'total_amount' => (float) $tail1['total_amount'] + (float) $tail2['total_amount'],
            'loan_count' => (int) $tail1['loan_count'] + (int) $tail2['loan_count'],
        ];

        $row = 12;
        $totalAmount = 0.0;
        $totalCount = 0;
        foreach ($combined as $band) {
            $sheet->setCellValue("K{$row}", $band['label']);
            $this->style($sheet, "K{$row}", false, self::FILL_BLUE, true);
            $this->amount($sheet, "L{$row}", $band['total_amount']);
            $this->intCell($sheet, "M{$row}", $band['loan_count']);
            $this->style($sheet, "L{$row}:M{$row}", false, null, true);
            $totalAmount += (float) $band['total_amount'];
            $totalCount += (int) $band['loan_count'];
            $row++;
        }

        $sheet->setCellValue("K{$row}", 'Total');
        $this->amount($sheet, "L{$row}", $totalAmount);
        $this->intCell($sheet, "M{$row}", $totalCount);
        $this->style($sheet, "K{$row}:M{$row}", true, self::FILL_BLUE, true);
    }

    private function writeBookBalance($sheet): void
    {
        $this->merge($sheet, 'A16:B16');
        $sheet->setCellValue('A16', 'Loan Book Balance as at End of Quarter (Including Interest):');
        $this->style($sheet, 'A16:B16', true, null, true, Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A17', 'Amount (NAD)');
        $sheet->setCellValue('B17', 'No.');
        $this->style($sheet, 'A17', true, self::FILL_GRAY, true);
        $this->style($sheet, 'B17', false, self::FILL_GRAY, true);

        $bb = $this->sections['BOOK_BALANCE'][0] ?? ['total_amount' => 0.0, 'loan_count' => 0];
        $this->amount($sheet, 'A18', $bb['total_amount']);
        $this->intCell($sheet, 'B18', $bb['loan_count']);
        $this->style($sheet, 'A18:B18', false, null, true);
    }

    private function writeWrittenOffTable($sheet): void
    {
        $this->merge($sheet, 'A20:E20');
        $sheet->setCellValue('A20', 'Total Loans Written Off (Bad Debts)');
        $this->style($sheet, 'A20', true, null, false, Alignment::HORIZONTAL_LEFT);

        $this->rowValues($sheet, 'B21', ['Capital (NAD)', 'Interest (NAD)', 'Total (NAD)', 'No.']);
        $this->style($sheet, 'B21:E21', true, self::FILL_GRAY, true);

        $rows = array_values($this->sections['WRITTEN_OFF']);
        $totals = ['capital_amount' => 0.0, 'interest_amount' => 0.0, 'total_amount' => 0.0, 'loan_count' => 0];
        $row = 22;
        foreach ($rows as $r) {
            $sheet->setCellValue("A{$row}", $r['month_label']);
            $this->style($sheet, "A{$row}", true, self::FILL_GRAY, true);
            $this->amount($sheet, "B{$row}", $r['capital_amount']);
            $this->amount($sheet, "C{$row}", $r['interest_amount']);
            $this->amount($sheet, "D{$row}", $r['total_amount']);
            $this->intCell($sheet, "E{$row}", $r['loan_count']);
            $this->style($sheet, "B{$row}:E{$row}", false, null, true);
            foreach ($totals as $k => &$v) {
                $v += (float) $r[$k];
            }
            unset($v);
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'Total');
        $this->amount($sheet, "B{$row}", $totals['capital_amount']);
        $this->amount($sheet, "C{$row}", $totals['interest_amount']);
        $this->amount($sheet, "D{$row}", $totals['total_amount']);
        $this->intCell($sheet, "E{$row}", (int) $totals['loan_count']);
        $this->style($sheet, "A{$row}:E{$row}", true, self::FILL_GRAY, true);
    }

    private function writeExpensesTable($sheet): void
    {
        $sheet->setCellValue('G16', 'Expenses ');
        $this->style($sheet, 'G16', true, null, false);
        $sheet->setCellValue('H16', 'Amount (NAD)');
        $this->style($sheet, 'H16', true, self::FILL_GRAY, true);

        $rows = array_values($this->sections['EXPENSES']);
        $labels = ['G17', 'G18', 'G19'];
        $values = ['H17', 'H18', 'H19'];
        $total = 0.0;
        foreach ($rows as $i => $r) {
            if (!isset($labels[$i])) {
                break;
            }
            $sheet->setCellValue($labels[$i], $r['month_label']);
            $this->style($sheet, $labels[$i], true, self::FILL_GRAY, true);
            $this->amount($sheet, $values[$i], $r['total_amount']);
            $this->style($sheet, $values[$i], false, null, true);
            $total += (float) $r['total_amount'];
        }

        $sheet->setCellValue('G20', 'Total');
        $this->style($sheet, 'G20', true, self::FILL_GRAY, true);
        $this->amount($sheet, 'H20', $total);
        $this->style($sheet, 'H20', true, null, true);
    }

    private function writeInterestIncome($sheet): void
    {
        $this->merge($sheet, 'G22:I22');
        $sheet->setCellValue('G22', 'Quarterly Interest Income- Segment');
        $this->style($sheet, 'G22', true, null, false, Alignment::HORIZONTAL_LEFT);

        $sheet->setCellValue('H23', 'Amount (NAD)');
        $this->style($sheet, 'H23', true, self::FILL_GRAY, true);

        $total = (float) array_sum(array_column($this->sections['INTEREST_INCOME'], 'total_amount'));
        $sheet->setCellValue('G24', 'Total');
        $this->style($sheet, 'G24', true, self::FILL_GRAY, true);
        $this->amount($sheet, 'H24', $total);
        $this->style($sheet, 'H24', true, self::FILL_GRAY, true);
    }

    private function writeLevyTable($sheet): void
    {
        $this->merge($sheet, 'K20:M20');
        $sheet->setCellValue('K20', 'Levies Payable to NAMFISA on Capital Disbursed Loans (Less Bad Debts)');
        $this->style($sheet, 'K20', true, null, false, Alignment::HORIZONTAL_LEFT);

        $sheet->setCellValue('L21', 'Amount (NAD)');
        $this->style($sheet, 'L21', true, self::FILL_GRAY, true);

        $rows = array_values($this->sections['LEVY']);
        $labels = ['K22', 'K23', 'K24'];
        $values = ['L22', 'L23', 'L24'];
        $total = 0.0;
        foreach ($rows as $i => $r) {
            if (!isset($labels[$i])) {
                break;
            }
            $net = (float) $r['total_amount'] - (float) $r['capital_amount'];
            $sheet->setCellValue($labels[$i], $r['month_label']);
            $this->style($sheet, $labels[$i], true, self::FILL_GRAY, true);
            $this->amount($sheet, $values[$i], $net);
            $this->style($sheet, $values[$i], false, self::FILL_GRAY, true);
            $total += $net;
        }

        $sheet->setCellValue('K25', 'Total');
        $this->amount($sheet, 'L25', $total);
        $this->style($sheet, 'K25:L25', true, self::FILL_GRAY, true);
    }

    private function writeSignatureBlock($sheet): void
    {
        $sheet->setCellValue('A28', 'Signature:________________________');
        $sheet->setCellValue('H28', 'DATE:');
        $this->style($sheet, 'H28', true, null, false, Alignment::HORIZONTAL_RIGHT);
        $sheet->setCellValue('I28', '_____________________________');

        $sheet->setCellValue('A30', 'Name of Representative:');
        $this->merge($sheet, 'C30:F30');
        $this->style($sheet, 'C30:F30', true, null, true, Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A31', 'PRINCIPAL OFFICER');
        $this->style($sheet, 'A31', true, null, false);
    }

    private function merge($sheet, string $range): void
    {
        $sheet->mergeCells($range);
    }

    private function rowValues($sheet, string $startCoord, array $values): void
    {
        $sheet->fromArray($values, null, $startCoord);
    }

    private function style($sheet, string $range, bool $bold, ?string $fill, bool $border, ?string $align = null): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold($bold);
        if ($fill !== null) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
        if ($border) {
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        if ($align !== null) {
            $style->getAlignment()->setHorizontal($align);
        }
    }

    private function amount($sheet, string $coord, $value): void
    {
        $sheet->setCellValue($coord, round((float) $value, 2));
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
    }

    private function intCell($sheet, string $coord, $value): void
    {
        $sheet->setCellValue($coord, (int) $value);
    }

    public function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
