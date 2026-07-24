<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Cash Book export -- styled to match the rest of the app's Excel exports
 * (General Journal, Trial Balance, etc.): title band and column headers in
 * the company's own branding color (white-label, from Company settings),
 * bordered data rows, and a bold totals/closing-balance footer.
 */
class CashBookExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';
    private const FILL_TOTAL = 'D8D8D8';

    public static function build(array $account, array $cashBook, string $fromDate, string $toDate): Spreadsheet
    {
        $brandColor = ltrim((new Company())->primary()['primary_color'] ?? '25a9e0', '#');

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cash Book');

        $widths = ['A' => 14, 'B' => 20, 'C' => 44, 'D' => 16, 'E' => 16, 'F' => 16];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'CASH BOOK - ' . ($account['account_code'] ?? '') . ' - ' . ($account['account_name'] ?? ''));
        self::style($sheet, 'A1:F1', true, $brandColor, false, 14, 'FFFFFF');

        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', 'Period: ' . date('d/m/Y', strtotime($fromDate)) . ' to ' . date('d/m/Y', strtotime($toDate)));
        self::style($sheet, 'A2:F2', false, null, false);

        $row = 4;
        $sheet->fromArray(['Date', 'Reference', 'Description', 'Debit (In)', 'Credit (Out)', 'Balance'], null, "A{$row}");
        self::style($sheet, "A{$row}:F{$row}", true, $brandColor, true, null, 'FFFFFF');
        $row++;

        $sheet->setCellValue("C{$row}", 'Opening Balance');
        self::amount($sheet, "F{$row}", $cashBook['opening_balance']);
        self::style($sheet, "A{$row}:F{$row}", true, self::FILL_TOTAL, true);
        $row++;

        foreach ($cashBook['lines'] as $line) {
            $sheet->setCellValue("A{$row}", date('d/m/Y', strtotime($line['journal_date'])));
            $sheet->setCellValue("B{$row}", $line['reference_no'] ?: '');
            $sheet->setCellValue("C{$row}", $line['description']);
            if ($line['debit'] > 0) {
                self::amount($sheet, "D{$row}", $line['debit']);
            }
            if ($line['credit'] > 0) {
                self::amount($sheet, "E{$row}", $line['credit']);
            }
            self::amount($sheet, "F{$row}", $line['running_balance']);
            self::style($sheet, "A{$row}:F{$row}", false, null, true);
            $row++;
        }

        $totalDebit = round((float) array_sum(array_column($cashBook['lines'], 'debit')), 2);
        $totalCredit = round((float) array_sum(array_column($cashBook['lines'], 'credit')), 2);

        $sheet->setCellValue("C{$row}", 'Totals');
        self::amount($sheet, "D{$row}", $totalDebit);
        self::amount($sheet, "E{$row}", $totalCredit);
        self::style($sheet, "A{$row}:F{$row}", true, self::FILL_TOTAL, true);
        $row++;

        $sheet->setCellValue("C{$row}", 'Closing Balance');
        self::amount($sheet, "F{$row}", $cashBook['closing_balance']);
        self::style($sheet, "A{$row}:F{$row}", true, self::FILL_TOTAL, true);

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

    private static function amount($sheet, string $coord, $value): void
    {
        $sheet->setCellValue($coord, round((float) $value, 2));
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $sheet->getStyle($coord)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
