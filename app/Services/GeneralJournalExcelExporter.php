<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * General Journal export -- one row per journal line (paired debit/credit),
 * grouped visually by leaving the Date/Journal No blank on repeat lines of
 * the same transaction, matching the on-screen report. Title/header band
 * uses the company's own branding color (white-label, from Company
 * settings). A medium bottom border under the last line of each journal
 * entry marks where it ends, on top of a thin grid over every cell.
 */
class GeneralJournalExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';
    private const FILL_TOTAL = 'D8D8D8';

    public static function build(array $lines, string $fromDate, string $toDate): Spreadsheet
    {
        $brandColor = ltrim((new Company())->primary()['primary_color'] ?? '25a9e0', '#');

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('General Journal');

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);

        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'GENERAL JOURNAL');
        self::style($sheet, 'A1:E1', true, $brandColor, false, 14, 'FFFFFF');
        $sheet->setCellValue('A2', 'Period: ' . date('d/m/Y', strtotime($fromDate)) . ' to ' . date('d/m/Y', strtotime($toDate)));

        $row = 4;
        $sheet->fromArray(['Date', 'Journal No', 'Account Name', 'Debit', 'Credit'], null, "A{$row}");
        self::style($sheet, "A{$row}:E{$row}", true, $brandColor, true, null, 'FFFFFF');
        $row++;

        $groups = [];
        foreach ($lines as $line) {
            $groups[$line['journal_id']][] = $line;
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($groups as $group) {
            $groupStartRow = $row;
            foreach ($group as $i => $line) {
                $sheet->setCellValue("A{$row}", $i === 0 ? date('d/m/Y', strtotime($line['journal_date'])) : '');
                $sheet->setCellValue("B{$row}", $i === 0 ? $line['journal_no'] : ($i === 1 ? $line['reference_no'] : ''));
                $sheet->setCellValue("C{$row}", $line['account_code'] . ' - ' . $line['account_name']);
                $sheet->setCellValue("D{$row}", round((float) $line['debit'], 2));
                $sheet->setCellValue("E{$row}", round((float) $line['credit'], 2));
                $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
                $sheet->getStyle("A{$row}:E{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $totalDebit += (float) $line['debit'];
                $totalCredit += (float) $line['credit'];
                $row++;
            }
            // Mark the end of this journal entry with a heavier rule so
            // multi-line entries are clearly set apart from the next one.
            $sheet->getStyle("A" . ($row - 1) . ":E" . ($row - 1))->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        $sheet->setCellValue("C{$row}", 'Totals');
        $sheet->setCellValue("D{$row}", round($totalDebit, 2));
        $sheet->setCellValue("E{$row}", round($totalCredit, 2));
        self::style($sheet, "A{$row}:E{$row}", true, self::FILL_TOTAL, true);
        $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        return $spreadsheet;
    }

    private static function style($sheet, string $range, bool $bold, ?string $fill, bool $border, ?int $size = null, ?string $fontColor = null): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold($bold);
        if ($size) {
            $style->getFont()->setSize($size);
        }
        if ($fontColor) {
            $style->getFont()->getColor()->setRGB($fontColor);
        }
        if ($fill !== null) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
        if ($border) {
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
