<?php

namespace App\Services;

use App\Models\Company;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Shared styling for the app's Excel exports: title band and column headers
 * in the company's own branding color (white-label, from Company
 * settings), a neutral gray for totals rows, and thin borders throughout.
 * Exports with their own deliberate, client-matched styling (the MLR
 * NAMFISA filing replica, the AFS financial statements template) don't use
 * this -- their colors are pinned to match a real document, not the brand.
 */
class ExcelBrandStyle
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';
    private const FILL_TOTAL = 'D8D8D8';

    public static function numberFormat(): string
    {
        return self::NUMBER_FORMAT;
    }

    public static function brandColor(): string
    {
        return ltrim((new Company())->primary()['primary_color'] ?? '', '#') ?: '25A9E0';
    }

    /** Merged title band: brand color fill, bold white text, size 14. */
    public static function title($sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::brandColor());
    }

    /** Column header row: brand color fill, bold white text, thin border. */
    public static function header($sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::brandColor());
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /** Totals/subtotal row: bold, neutral gray fill, thin border. */
    public static function totals($sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FILL_TOTAL);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /** Plain thin grid border, no fill/font changes. */
    public static function border($sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
