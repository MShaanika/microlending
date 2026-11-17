<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The workbook for the applications received through the new system: everything we already know about each person (read-only, grey), and the three things
 * only the staff know (yellow): was the loan approved, for what amount, and on what date was the money paid out.
 *
 * write() makes the workbook, readFile() reads it back, parse() checks every answer and says what is wrong with each row that cannot be used.
 */
final class ApplicationApprovalSheet
{
    public const DATA_SHEET = 'Applications';
    public const HEAD_APPLICATION = 'Application no (do not change)';
    public const HEAD_APPROVED = 'Approved? (Yes or No)';
    public const HEAD_AMOUNT = 'Amount approved (N$)';
    public const HEAD_PAID_OUT = 'Date the money was paid out (only if approved)';

    private const HEADER = '1F4E79';
    private const FACTS = 'F2F4F7';
    private const ANSWER = 'FFF2A8';

    /** @param array<int, array<string,mixed>> $applications ApplicationLoanBook::fetch() rows */
    public function write(array $applications, string $path, string $asOf): void
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $this->howTo($book->getActiveSheet(), count($applications), $asOf);

        $s = $book->createSheet();
        $s->setTitle(self::DATA_SHEET);
        $heads = [self::HEAD_APPLICATION, 'Name', 'ID number', 'Gender', 'Phone', 'Address', 'Employer', 'Gross salary (N$)', 'Amount asked for (N$)', 'Term (months)', 'Applied on',
            self::HEAD_APPROVED, self::HEAD_AMOUNT, self::HEAD_PAID_OUT, 'Check (automatic)'];
        $s->fromArray($heads, null, 'A1');

        $r = 2;
        foreach ($applications as $a) {
            $name = trim(preg_replace('/\s+/', ' ', $a['applicant_first_name'] . ' ' . ($a['applicant_middle_name'] ?? '') . ' ' . $a['applicant_last_name']) ?? '');
            $values = [(string) $a['application_no'], $name, (string) ($a['applicant_id_number'] ?? ''), (string) ($a['applicant_gender'] ?? ''), (string) ($a['applicant_phone'] ?? ''),
                (string) ($a['applicant_address'] ?? ''), (string) ($a['employer_name'] ?? ''), (string) ($a['gross_salary'] ?? ''), (string) ($a['requested_amount'] ?? ''),
                (string) ($a['requested_term_months'] ?? ''), substr((string) ($a['created_at'] ?? ''), 0, 10)];
            foreach ($values as $i => $v) {
                $s->setCellValueExplicit(chr(65 + $i) . $r, $v, DataType::TYPE_STRING);
            }
            $s->setCellValue('O' . $r, '=IF(L' . $r . '="","Not answered yet",IF(L' . $r . '="No","OK - not approved",IF(L' . $r . '<>"Yes","Write Yes or No",'
                . 'IF(M' . $r . '="","Enter the amount approved",IF(NOT(ISNUMBER(--M' . $r . ')),"The amount must be a number",IF(--M' . $r . '<=0,"The amount must be above zero",IF(N' . $r . '="","Enter the date paid out",IF(AND(LEN(N' . $r . ')=10,MID(N' . $r . ',5,1)="-",MID(N' . $r . ',8,1)="-",ISNUMBER(--SUBSTITUTE(N' . $r . ',"-",""))),"OK","Write the date like 2026-10-30"))))))))');
            $r++;
        }
        $last = max(2, $r - 1);

        $s->getStyle('A1:O' . $last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C5CBD3');
        $s->getStyle('A1:O' . $last)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        for ($i = 2; $i <= $last; $i++) {
            $s->getRowDimension($i)->setRowHeight(34);
        }
        $s->getStyle('A2:K' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FACTS);
        $s->getStyle('L2:N' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ANSWER);
        $s->getStyle('L2:N' . $last)->getNumberFormat()->setFormatCode('@');
        $s->getStyle('L2:N' . $last)->getFont()->setBold(true)->setSize(12);
        $s->getStyle('L2:N' . $last)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $s->getStyle('L2:N' . $last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('C9A800');

        $s->getStyle('A1:O1')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
        $s->getStyle('A1:O1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER);
        $s->getStyle('L1:N1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9A7B00');
        $s->getStyle('A1:O1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getRowDimension(1)->setRowHeight(62);

        $ok = new Conditional();
        $ok->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('LEFT(O2,2)="OK"');
        $ok->getStyle()->getFont()->getColor()->setRGB('0B6B2E');
        $ok->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('D6F2DD');
        $bad = new Conditional();
        $bad->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('OR(LEFT(O2,5)="Enter",LEFT(O2,5)="Write",LEFT(O2,3)="The")');
        $bad->getStyle()->getFont()->getColor()->setRGB('9B1C1C');
        $bad->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('FBD9D9');
        $s->getStyle('O2:O' . $last)->setConditionalStyles([$ok, $bad]);
        $s->getStyle('O2:O' . $last)->getFont()->setBold(true);

        for ($i = 2; $i <= $last; $i++) {
            $a = $s->getCell('L' . $i)->getDataValidation();
            $a->setType(DataValidation::TYPE_LIST)->setFormula1('"Yes,No"')->setAllowBlank(true)->setShowDropDown(false)->setShowErrorMessage(true)
                ->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle('Approved?')->setError('Choose Yes or No from the list.')
                ->setShowInputMessage(true)->setPromptTitle('Approved?')->setPrompt('Yes = the loan was approved and paid out. No = it was not.');
            $m = $s->getCell('M' . $i)->getDataValidation();
            $m->setType(DataValidation::TYPE_CUSTOM)->setFormula1('=AND(ISNUMBER(--M' . $i . '),--M' . $i . '>0)')->setAllowBlank(true)->setShowErrorMessage(true)
                ->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle('Amount')->setError('Type the amount as a number only, like 4500 (no N$, no spaces).')
                ->setShowInputMessage(true)->setPromptTitle('Amount approved')->setPrompt('The amount actually approved and paid out, in N$. Numbers only.');
            $d = $s->getCell('N' . $i)->getDataValidation();
            $d->setType(DataValidation::TYPE_CUSTOM)->setFormula1('=AND(LEN(N' . $i . ')=10,MID(N' . $i . ',5,1)="-",MID(N' . $i . ',8,1)="-",ISNUMBER(--SUBSTITUTE(N' . $i . ',"-","")),--MID(N' . $i . ',6,2)>=1,--MID(N' . $i . ',6,2)<=12,--MID(N' . $i . ',9,2)>=1,--MID(N' . $i . ',9,2)<=31)')->setAllowBlank(true)
                ->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle('Date')->setError('Write the date like 2026-10-30 (year-month-day, with dashes).')
                ->setShowInputMessage(true)->setPromptTitle('Date paid out')->setPrompt('The day the money was paid to the customer, like 2026-10-30.');
        }

        foreach (['A' => 20, 'B' => 26, 'C' => 15, 'D' => 9, 'E' => 15, 'F' => 28, 'G' => 22, 'H' => 12, 'I' => 12, 'J' => 8, 'K' => 12, 'L' => 14, 'M' => 16, 'N' => 18, 'O' => 24] as $col => $w) {
            $s->getColumnDimension($col)->setWidth($w);
        }
        $s->freezePane('C2');
        $s->setAutoFilter('A1:O' . $last);
        $s->setSelectedCell('L2');
        $s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0)->setFitToPage(true);
        $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        $s->getHeaderFooter()->setOddFooter('&LLoan applications - ' . $asOf . '&RPage &P of &N');
        $s->getProtection()->setSheet(true)->setAutoFilter(false)->setSort(false)->setFormatColumns(false)->setFormatRows(false);

        $book->setActiveSheetIndex(1);
        (new Xlsx($book))->save($path);
    }

    /** @return array<int, array<string,mixed>> */
    public function readFile(string $path): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName(self::DATA_SHEET) ?? $book->getActiveSheet();
        $grid = $sheet->toArray(null, true, false, false);
        $head = array_shift($grid) ?: [];
        $col = [];
        foreach ($head as $i => $h) {
            $col[strtolower(trim((string) $h))] = $i;
        }
        foreach ([self::HEAD_APPLICATION, self::HEAD_APPROVED, self::HEAD_AMOUNT, self::HEAD_PAID_OUT] as $h) {
            if (!isset($col[strtolower($h)])) {
                throw new \RuntimeException('The sheet has no column headed "' . $h . '": use the sheet exactly as it was sent out.');
            }
        }
        $rows = [];
        foreach ($grid as $r) {
            $paid = $r[$col[strtolower(self::HEAD_PAID_OUT)]] ?? '';
            if ((is_int($paid) || is_float($paid)) && $paid > 0 && $paid < 100000) {
                $paid = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $paid)->format('Y-m-d'); // a real Excel date cell
            }
            $amount = $r[$col[strtolower(self::HEAD_AMOUNT)]] ?? '';
            $rows[] = [
                'application_no' => $r[$col[strtolower(self::HEAD_APPLICATION)]] ?? '',
                'approved' => $r[$col[strtolower(self::HEAD_APPROVED)]] ?? '',
                'amount' => is_float($amount) ? rtrim(rtrim(sprintf('%.2F', $amount), '0'), '.') : $amount,
                'paid_out' => $paid,
            ];
        }
        return $rows;
    }

    /**
     * @param array<int, array<string,mixed>> $rows readFile() output
     * @param array<string, array<string,mixed>> $applicationsByNo applications by application number (to know who is on the list, and when each applied)
     * @return array{approved: array<string, array{amount: float, paid_out: string}>, declined: int, unanswered: int, later: string[], rejected: array<int, array{row:int, application_no:string, why:string}>, warnings: array<int, array{application_no:string, note:string}>}
     */
    public function parse(array $rows, array $applicationsByNo, string $asOf, string $today): array
    {
        $out = ['approved' => [], 'declined' => 0, 'unanswered' => 0, 'later' => [], 'rejected' => [], 'warnings' => []];
        // An application that appears on more than one row is never guessed at: every one of its rows is refused.
        $count = [];
        foreach ($rows as $row) {
            $n = $this->tidy((string) ($row['application_no'] ?? ''));
            if ($n !== '') {
                $count[$n] = ($count[$n] ?? 0) + 1;
            }
        }

        foreach (array_values($rows) as $i => $row) {
            $line = $i + 2;
            $no = $this->tidy((string) ($row['application_no'] ?? ''));
            $answer = strtolower($this->tidy((string) ($row['approved'] ?? '')));
            if ($no === '' && $answer === '') {
                continue;
            }
            $reject = function (string $why) use (&$out, $line, $no): void {
                $out['rejected'][] = ['row' => $line, 'application_no' => $no, 'why' => $why];
            };
            if ($answer === '') {
                $out['unanswered']++;
                continue;
            }
            if (!isset($applicationsByNo[$no])) {
                $reject('this application number is not on the list (was the first column changed?)');
                continue;
            }
            if ($count[$no] > 1) {
                $reject('this application appears on more than one row of the sheet: keep one row and ask again');
                continue;
            }

            if (in_array($answer, ['no', 'n'], true)) {
                $out['declined']++;
                continue;
            }
            if (!in_array($answer, ['yes', 'y'], true)) {
                $reject('"Approved?" must be Yes or No');
                continue;
            }

            $amountText = preg_replace('/(?i)n\$|nad|\s/', '', $this->tidy((string) ($row['amount'] ?? ''))) ?? '';
            if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $amountText)) {
                $amountText = str_replace(',', '', $amountText); // 4,500 or 12,500.50: a comma only ever means thousands
            }
            if ($amountText === '' || !preg_match('/^\d+(\.\d{1,2})?$/', $amountText) || (float) $amountText <= 0) {
                $reject('the amount approved must be digits only, like 4500 or 4500.50 (a dot for cents, never a comma)');
                continue;
            }
            $amount = (float) $amountText;
            if ($amount > 500000) {
                $reject('the amount approved is unbelievably large (over N$ 500 000): check it');
                continue;
            }

            $paidOut = $this->parseDate((string) ($row['paid_out'] ?? ''));
            if ($paidOut === null) {
                $reject('a real date the money was paid out is needed, like 2026-10-30');
                continue;
            }
            $appliedOn = substr((string) ($applicationsByNo[$no]['created_at'] ?? ''), 0, 10);
            if ($paidOut > $today) {
                $reject('the date paid out is in the future');
                continue;
            }
            if ($appliedOn !== '' && $paidOut < $appliedOn) {
                $reject('the money cannot be paid out (' . $paidOut . ') before the application was made (' . $appliedOn . ')');
                continue;
            }

            $dueOn = ApplicationLoanBook::firstInstalmentDate($paidOut, (int) ($applicationsByNo[$no]['payment_day'] ?? 0));
            if ($dueOn <= $asOf) {
                $reject('the first instalment fell due on ' . $dueOn . ', before the month end, and this sheet does not record repayments: the account cannot be reported from here');
                continue;
            }

            $asked = (float) ($applicationsByNo[$no]['requested_amount'] ?? 0);
            if ($asked > 0 && $amount > $asked) {
                $out['warnings'][] = ['application_no' => $no, 'note' => 'approved N$ ' . $amountText . ' is more than the N$ ' . $asked . ' asked for'];
            }
            $out['approved'][$no] = ['amount' => $amount, 'paid_out' => $paidOut];
            if ($paidOut > $asOf) {
                $out['later'][] = $no; // paid out after this month end: belongs to a later file
            }
        }
        $byPerson = [];
        foreach (array_keys($out['approved']) as $no) {
            $id = preg_replace('/\D/', '', (string) ($applicationsByNo[$no]['applicant_id_number'] ?? '')) ?? '';
            if ($id !== '') {
                $byPerson[$id][] = $no;
            }
        }
        foreach ($byPerson as $nos) {
            if (count($nos) > 1) {
                $out['warnings'][] = ['application_no' => implode(', ', $nos), 'note' => 'approved more than once for the same ID number: confirm these are separate loans, not one loan entered twice'];
            }
        }

        return $out;
    }

    private function tidy(string $v): string
    {
        return trim(preg_replace('/[\p{Z}\x{200B}]+/u', ' ', $v) ?? $v);
    }

    /** Only YYYY-MM-DD (real Excel date cells are turned into it by readFile): a day/month order is never guessed. */
    private function parseDate(string $raw): ?string
    {
        $raw = $this->tidy($raw);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) && $y >= 2000 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private function howTo(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $how, int $count, string $asOf): void
    {
        $how->setTitle('How to fill in');
        $how->setShowGridlines(false);
        $how->getColumnDimension('A')->setWidth(4);
        $how->getColumnDimension('B')->setWidth(110);
        $how->setCellValue('B1', 'Loan applications: which were approved?');
        $how->getStyle('B1')->getFont()->setBold(true)->setSize(20)->setColor(new Color(self::HEADER));
        $how->setCellValue('B2', $count . ' application(s) received online  -  for the Creditinfo report of the month ending ' . $asOf);
        $how->getStyle('B2')->getFont()->setSize(12)->setColor(new Color('52606D'));
        $how->setCellValue('B4', 'We already have every customer\'s details from the online application. Creditinfo also needs to know whether the loan was really granted, for how much, and when. Only you know that.');
        $how->getStyle('B4')->getAlignment()->setWrapText(true);
        $how->getRowDimension(4)->setRowHeight(34);

        $steps = [
            ['STEP 1', 'Open the sheet "' . self::DATA_SHEET . '" (tab at the bottom). Each row is one application. The GREY columns are what we already know: do not change them.'],
            ['STEP 2', 'In the YELLOW column "Approved?" choose Yes if the loan was approved and paid out, or No if it was not. Leave it empty if you do not know yet.'],
            ['STEP 3', 'For a Yes: type the amount that was really approved and paid out (numbers only, like 4500) and the date the money was paid out (like 2026-10-30). It may differ from the amount asked for.'],
            ['STEP 4', 'The last column "Check" turns GREEN with OK when the row is complete, and RED with the reason when something is missing or wrong.'],
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
            'Do not delete rows, add rows or change the grey columns: the application number is how your answer finds the customer.',
            'The yellow cells refuse wrong input and tell you why. If a message appears, correct the entry; nothing is lost.',
            'The date the money was paid out decides which month\'s report the loan goes into, so please be exact.',
            'If a customer\'s details look wrong (name, ID number, phone), tell us instead of changing them here.',
            'This file contains customer names, ID numbers and phone numbers: send it only to the people who need it, and delete it when finished.',
        ];
        $row = 13;
        foreach ($notes as $text) {
            $how->setCellValue('B' . $row, '-  ' . $text);
            $how->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $how->getRowDimension($row)->setRowHeight(34);
            $row++;
        }
        $how->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(1)->setFitToPage(true);
    }
}
