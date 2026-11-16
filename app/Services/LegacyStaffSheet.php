<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The "missing customer details" workbook handed to staff: one row per customer whose ID cannot be reported yet, the facts they need to find the ID copy,
 * and two columns to fill in (ID number, date of birth when it is not a Namibian ID). The first column (customer number) must not be touched: it is how the
 * answers find their customer again, so everything except the two answer columns is locked.
 */
final class LegacyStaffSheet
{
    public const DATA_SHEET = 'Missing details';
    public const HEAD_BORROWER = 'Customer no (do not change)';
    public const HEAD_ID = 'Correct ID number (fill in)';
    public const HEAD_DOB = 'Date of birth (only if NOT an 11-digit Namibian ID)';

    /** @param array<int, array<string,mixed>> $rows LegacyIdFixList::build() output */
    public function write(array $rows, string $path, string $asOf): void
    {
        $book = new Spreadsheet();
        $how = $book->getActiveSheet();
        $how->setTitle('How to fill in');
        $lines = [
            'Missing customer details - needed by Creditinfo for the month ending ' . $asOf,
            '',
            "Creditinfo must receive every loan with the customer's ID number. The customers on the next sheet have no usable ID number in the loan system.",
            '1. Open the sheet "' . self::DATA_SHEET . '". Find the customer\'s ID copy (the name, phone number and loan reference will help you find the file).',
            '2. Type the ID number in the yellow column "Correct ID number" - digits only, no spaces. A Namibian ID has 11 digits.',
            '3. If the customer does NOT have an 11-digit Namibian ID (for example a foreign ID with 5 to 13 digits), also give the date of birth like 1985-05-15.',
            '4. A "Suggested ID" is only a lead taken from the customer\'s online application. Confirm it against the ID copy before you type it in; never copy it blindly.',
            '5. Leave a row empty if you cannot find the ID. Do not delete rows, do not change the first column, do not add rows.',
            '6. Save the file and send it back as it is (keep it an .xlsx file).',
            '',
            'Also correct the ID in the loan system itself, so that next month the customer is complete there.',
        ];
        foreach ($lines as $i => $text) {
            $how->setCellValue('A' . ($i + 1), $text);
        }
        $how->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $how->getColumnDimension('A')->setWidth(140);
        $how->getStyle('A1:A' . count($lines))->getAlignment()->setWrapText(true);

        $sheet = $book->createSheet();
        $sheet->setTitle(self::DATA_SHEET);
        $heads = [self::HEAD_BORROWER, 'Name', 'Phone', 'Loan reference(s)', 'What is wrong', 'Suggested ID (a lead only - confirm against the ID copy)', 'How sure', self::HEAD_ID, self::HEAD_DOB];
        $sheet->fromArray($heads, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            foreach (['borrower_id', 'name', 'phone', 'loans', 'problem', 'suggested_id', 'confidence'] as $i => $key) {
                $sheet->setCellValueExplicit(chr(65 + $i) . $r, (string) $row[$key], DataType::TYPE_STRING);
            }
            $r++;
        }
        $last = max(2, $r - 1);

        // Answers are text so that a leading zero or a long number is never turned into a number by the spreadsheet program.
        $sheet->getStyle('H2:I' . $last)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('H2:I' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2A8');
        $sheet->getStyle('H2:I' . $last)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E2EC');
        $sheet->getStyle('A1:I1')->getAlignment()->setWrapText(true)->setVertical('top');
        foreach (['A' => 14, 'B' => 28, 'C' => 15, 'D' => 22, 'E' => 42, 'F' => 24, 'G' => 10, 'H' => 24, 'I' => 24] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('C2');
        $sheet->setAutoFilter('A1:I' . $last);
        $sheet->getProtection()->setSheet(true);
        $sheet->getProtection()->setAutoFilter(false);
        $sheet->getProtection()->setSort(false);

        $book->setActiveSheetIndex(1);
        (new Xlsx($book))->save($path);
    }
}
