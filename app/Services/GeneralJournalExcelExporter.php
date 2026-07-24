<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * General Journal export -- one row per journal line (paired debit/credit),
 * grouped visually by leaving the Date/Journal No blank on repeat lines of
 * the same transaction, matching the on-screen report.
 */
class GeneralJournalExcelExporter
{
    private const NUMBER_FORMAT = '#,##0.00;[Red](#,##0.00)';

    public static function build(array $lines, string $fromDate, string $toDate): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('General Journal');

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(36);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);

        $sheet->mergeCells('A1:E1');
        $sheet->setCellValue('A1', 'GENERAL JOURNAL');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAF7');
        $sheet->setCellValue('A2', 'Period: ' . $fromDate . ' to ' . $toDate);

        $row = 4;
        $sheet->fromArray(['Date', 'Journal No', 'Account Name', 'Debit', 'Credit'], null, "A{$row}");
        $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
        $row++;

        $groups = [];
        foreach ($lines as $line) {
            $groups[$line['journal_id']][] = $line;
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($groups as $group) {
            foreach ($group as $i => $line) {
                $sheet->setCellValue("A{$row}", $i === 0 ? date('Y-m-d', strtotime($line['journal_date'])) : '');
                $sheet->setCellValue("B{$row}", $i === 0 ? $line['journal_no'] : ($i === 1 ? $line['reference_no'] : ''));
                $sheet->setCellValue("C{$row}", $line['account_code'] . ' - ' . $line['account_name']);
                $sheet->setCellValue("D{$row}", round((float) $line['debit'], 2));
                $sheet->setCellValue("E{$row}", round((float) $line['credit'], 2));
                $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
                $totalDebit += (float) $line['debit'];
                $totalCredit += (float) $line['credit'];
                $row++;
            }
        }

        $sheet->setCellValue("C{$row}", 'Totals');
        $sheet->setCellValue("D{$row}", round($totalDebit, 2));
        $sheet->setCellValue("E{$row}", round($totalCredit, 2));
        $sheet->getStyle("C{$row}:E{$row}")->getFont()->setBold(true);
        $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);

        return $spreadsheet;
    }

    public static function save(Spreadsheet $spreadsheet, string $path): void
    {
        (new Xlsx($spreadsheet))->save($path);
    }
}
