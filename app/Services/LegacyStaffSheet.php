<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The "missing customer details" workbook handed to staff: one row per customer whose ID cannot be reported yet, the facts they need to find the ID copy,
 * and two columns to fill in (ID number, date of birth when it is not a Namibian ID). The first column (customer number) must not be touched: it is how the
 * answers find their customer again, so everything except the two answer columns is locked.
 *
 * Built so that a person can fill it in without help: a short instruction sheet with an example, grey read-only facts, yellow answer cells that refuse
 * wrong input with an explanation, and a live "Check" column that says OK / what is still needed as they type. It prints on A4 landscape with the headings
 * repeated on every page.
 */
final class LegacyStaffSheet
{
    public const DATA_SHEET = 'Missing details';
    public const HEAD_BORROWER = 'Customer no (do not change)';
    public const HEAD_ID = 'Correct ID number (fill in)';
    public const HEAD_DOB = 'Date of birth (only if NOT an 11-digit Namibian ID)';

    private const INK = '1F2A37';
    private const HEADER = '1F4E79';
    private const FACTS = 'F2F4F7';
    private const ANSWER = 'FFF2A8';
    private const LEAD = 'E3F0FB';

    /** @param array<int, array<string,mixed>> $rows LegacyIdFixList::build() output */
    public function write(array $rows, string $path, string $asOf): void
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $this->howTo($book->getActiveSheet(), count($rows), $asOf);

        $sheet = $book->createSheet();
        $sheet->setTitle(self::DATA_SHEET);

        $heads = [self::HEAD_BORROWER, 'Name', 'Phone', 'Loan reference(s)', 'What is wrong', 'Suggested ID (a lead only - confirm against the ID copy)', 'How sure', self::HEAD_ID, self::HEAD_DOB, 'Check (automatic)'];
        $sheet->fromArray($heads, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            foreach (['borrower_id', 'name', 'phone', 'loans', 'problem', 'suggested_id', 'confidence'] as $i => $key) {
                $sheet->setCellValueExplicit(chr(65 + $i) . $r, (string) $row[$key], DataType::TYPE_STRING);
            }
            // Live feedback while typing: OK, or what is still missing. (Text-formatted cells hold digits as text, so "--" turns them into numbers for the test.)
            $sheet->setCellValue('J' . $r, '=IF(H' . $r . '="","Not filled in yet",'
                . 'IF(NOT(ISNUMBER(--H' . $r . ')),"Digits only please",'
                . 'IF(LEN(H' . $r . ')=11,"OK - Namibian ID",'
                . 'IF(OR(LEN(H' . $r . ')<5,LEN(H' . $r . ')>13),"Wrong length: "&LEN(H' . $r . ')&" digits",'
                . 'IF(I' . $r . '="","Add the date of birth","OK")))))');
            $r++;
        }
        $last = max(2, $r - 1);
        $all = 'A1:J' . $last;

        // Whole table: borders, wrapped text, top aligned, a comfortable row height.
        $sheet->getStyle($all)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C5CBD3');
        $sheet->getStyle($all)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('A2:J' . $last)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::INK));
        for ($i = 2; $i <= $last; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(34);
        }

        // Facts (read-only) are grey, the lead is light blue, the two answer cells are yellow.
        $sheet->getStyle('A2:E' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FACTS);
        $sheet->getStyle('F2:G' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::LEAD);
        $sheet->getStyle('H2:I' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ANSWER);
        $sheet->getStyle('H2:I' . $last)->getNumberFormat()->setFormatCode('@'); // text: a leading zero or a long number is never changed
        $sheet->getStyle('H2:I' . $last)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('H2:I' . $last)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $sheet->getStyle('H2:I' . $last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('C9A800');

        // Header row.
        $sheet->getStyle('A1:J1')->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
        $sheet->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER);
        $sheet->getStyle('H1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9A7B00');
        $sheet->getStyle('A1:J1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(62);

        // The automatic check turns green on OK and red on a problem.
        $ok = new Conditional();
        $ok->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('LEFT(J2,2)="OK"');
        $ok->getStyle()->getFont()->getColor()->setRGB('0B6B2E');
        $ok->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('D6F2DD');
        $bad = new Conditional();
        $bad->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('OR(LEFT(J2,5)="Wrong",LEFT(J2,6)="Digits",LEFT(J2,3)="Add")')
            ->getStyle()->getFont()->getColor()->setRGB('9B1C1C');
        $bad->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('FBD9D9');
        $sheet->getStyle('J2:J' . $last)->setConditionalStyles([$ok, $bad]);
        $sheet->getStyle('J2:J' . $last)->getFont()->setBold(true);

        // Input rules with a plain explanation when something wrong is typed.
        for ($i = 2; $i <= $last; $i++) {
            $v = $sheet->getCell('H' . $i)->getDataValidation();
            $v->setType(DataValidation::TYPE_CUSTOM)->setFormula1('=AND(ISNUMBER(--H' . $i . '),LEN(H' . $i . ')>=5,LEN(H' . $i . ')<=13)')
                ->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
                ->setErrorTitle('ID number')->setError('Type digits only (no letters, spaces or dashes). A Namibian ID has 11 digits; another ID has 5 to 13.')
                ->setShowInputMessage(true)->setPromptTitle('ID number')->setPrompt('Digits only. Namibian ID = 11 digits. Copy it from the ID copy, not from the blue suggestion.');
            $d = $sheet->getCell('I' . $i)->getDataValidation();
            $d->setType(DataValidation::TYPE_CUSTOM)->setFormula1('=AND(LEN(I' . $i . ')=10,MID(I' . $i . ',5,1)="-",MID(I' . $i . ',8,1)="-")')
                ->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
                ->setErrorTitle('Date of birth')->setError('Write the date like 1985-05-15 (year-month-day, with dashes).')
                ->setShowInputMessage(true)->setPromptTitle('Date of birth')->setPrompt('Only when the ID is NOT an 11-digit Namibian ID. Write 1985-05-15.');
        }

        foreach (['A' => 11, 'B' => 26, 'C' => 14, 'D' => 20, 'E' => 34, 'F' => 22, 'G' => 9, 'H' => 22, 'I' => 20, 'J' => 22] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('C2');
        $sheet->setAutoFilter('A1:J' . $last);
        $sheet->setSelectedCell('H2');

        // Printing: A4 landscape, one page wide, headings on every page, page numbers.
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.6);
        $sheet->getHeaderFooter()->setOddFooter('&LMissing customer details - ' . $asOf . '&RPage &P of &N');

        // Lock everything except the two answer columns (no password, so staff can still sort and filter).
        $sheet->getProtection()->setSheet(true)->setAutoFilter(false)->setSort(false)->setFormatColumns(false)->setFormatRows(false);

        $book->setActiveSheetIndex(1);
        (new Xlsx($book))->save($path);
    }

    private function howTo(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $how, int $customers, string $asOf): void
    {
        $how->setTitle('How to fill in');
        $how->setShowGridlines(false);
        $how->getColumnDimension('A')->setWidth(4);
        $how->getColumnDimension('B')->setWidth(110);

        $how->setCellValue('B1', 'Missing customer details');
        $how->getStyle('B1')->getFont()->setBold(true)->setSize(20)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(self::HEADER));
        $how->setCellValue('B2', 'For the Creditinfo report of the month ending ' . $asOf . '  -  ' . $customers . ' customer(s) to complete');
        $how->getStyle('B2')->getFont()->setSize(12)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('52606D'));

        $how->setCellValue('B4', 'Why: Creditinfo needs the ID number of every customer who has a loan with us. The customers on the next sheet have no usable ID number in the loan system yet.');

        $steps = [
            ['STEP 1', 'Open the sheet "' . self::DATA_SHEET . '" (tab at the bottom). Each row is one customer. Use the name, phone and loan reference to find the customer\'s ID copy.'],
            ['STEP 2', 'Type the ID number in the YELLOW column "Correct ID number". Digits only: no spaces, no dashes, no letters. A Namibian ID has 11 digits.'],
            ['STEP 3', 'Only if the customer does NOT have an 11-digit Namibian ID (for example a foreign ID): also type the date of birth in the next yellow column, like 1985-05-15.'],
            ['STEP 4', 'Look at the last column "Check". It turns GREEN and says OK when the row is complete, and RED with the reason when something is wrong.'],
            ['STEP 5', 'Save the file (keep it an .xlsx Excel file) and send it back as it is.'],
        ];
        $row = 6;
        foreach ($steps as [$tag, $text]) {
            $how->setCellValue('B' . $row, $tag . '   ' . $text);
            $how->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $how->getRowDimension($row)->setRowHeight(36);
            $row++;
        }
        $how->getStyle('B6:B10')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('9AA5B1');

        $how->setCellValue('B12', 'Good to know');
        $how->getStyle('B12')->getFont()->setBold(true)->setSize(13);
        $notes = [
            'The BLUE columns hold a "Suggested ID" taken from the customer\'s online application. It is only a lead: confirm it against the ID copy, never copy it blindly.',
            'Leave a row empty if you cannot find the ID. Do not delete rows, do not add rows and do not change the grey columns (the customer number is how your answer finds the customer).',
            'The yellow cells refuse wrong input and tell you why. If a message appears, correct the entry; nothing is lost.',
            'Please also correct the ID in the loan system itself, so that next month the customer is complete there.',
            'This file contains customer names and phone numbers: send it only to the people who need it, and delete it when finished.',
        ];
        $row = 13;
        foreach ($notes as $text) {
            $how->setCellValue('B' . $row, '-  ' . $text);
            $how->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $how->getRowDimension($row)->setRowHeight(34);
            $row++;
        }

        $how->setCellValue('B' . ($row + 1), 'Example');
        $how->getStyle('B' . ($row + 1))->getFont()->setBold(true)->setSize(13);
        $how->setCellValue('B' . ($row + 2), 'Namibian ID:  85051500123  (11 digits, the first six are the birth date 15 May 1985).        Another ID:  1234567  with date of birth 1985-05-15.');
        $how->getStyle('B' . ($row + 2))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ANSWER);
        $how->getStyle('B4')->getAlignment()->setWrapText(true);
        $how->getRowDimension(4)->setRowHeight(34);

        $how->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(1);
        $how->getPageSetup()->setFitToPage(true);
    }
}
