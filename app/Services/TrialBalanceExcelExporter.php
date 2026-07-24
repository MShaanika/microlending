<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Grouped trial balance export -- mirrors the on-screen report: a bold
 * subtotal row per account type, individual accounts indented beneath it,
 * grand totals at the bottom.
 */
class TrialBalanceExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';

    public static function build(array $result, string $asOfDate): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Trial Balance');

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(16);

        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A1', 'TRIAL BALANCE');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');
        $sheet->setCellValue('A2', 'As at: ' . $asOfDate);

        $row = 4;
        $sheet->fromArray(['Code', 'Account', 'Debit', 'Credit'], null, "A{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $row++;

        foreach ($result['groups'] as $groupLabel => $group) {
            if (empty($group['rows'])) {
                continue;
            }

            $sheet->setCellValue("A{$row}", strtoupper($groupLabel) . ' - TOTALS');
            $sheet->setCellValue("C{$row}", round($group['debit_total'], 2));
            $sheet->setCellValue("D{$row}", round($group['credit_total'], 2));
            $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
            $sheet->getStyle("C{$row}:D{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
            $row++;

            foreach ($group['rows'] as $r) {
                $sheet->setCellValue("A{$row}", $r['account_code'] ?? '');
                $sheet->setCellValue("B{$row}", '    ' . $r['account_name']);
                $sheet->setCellValue("C{$row}", $r['debit_balance'] > 0 ? round($r['debit_balance'], 2) : null);
                $sheet->setCellValue("D{$row}", $r['credit_balance'] > 0 ? round($r['credit_balance'], 2) : null);
                $sheet->getStyle("C{$row}:D{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
                if (!empty($r['is_computed'])) {
                    $sheet->getStyle("A{$row}:D{$row}")->getFont()->setItalic(true);
                }
                $row++;
            }
        }

        $sheet->setCellValue("A{$row}", 'GRAND TOTALS');
        $sheet->setCellValue("C{$row}", round($result['grand_total_debit'], 2));
        $sheet->setCellValue("D{$row}", round($result['grand_total_credit'], 2));
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle("C{$row}:D{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        return $spreadsheet;
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
