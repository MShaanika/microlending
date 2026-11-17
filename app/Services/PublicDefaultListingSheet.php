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
 * The staff template for LISTING customers as defaulters at Creditinfo (Public Defaults, ACTION = A). Staff type the details first;
 * write() makes the blank workbook, readFile() reads it back, parse() checks every row and says what is wrong with each one that cannot be used,
 * and build() turns the good rows into the exact 36-column pipe-delimited lines (CreditinfoPublicDefaultFileService::renderRecord).
 * Nothing here touches the database or the network.
 */
final class PublicDefaultListingSheet
{
    public const DATA_SHEET = 'Defaulters to list';
    public const ROWS = 200;

    /** key => [header, required, width]. The order is the column order of the sheet (A, B, C ...). */
    public const COLUMNS = [
        'first_name'  => ['First name *', true, 16],
        'second_name' => ['Second name', false, 14],
        'surname'     => ['Surname *', true, 16],
        'id_type'     => ['ID type * (National ID or Passport)', true, 14],
        'id_number'   => ['ID or passport number *', true, 17],
        'dob'         => ['Date of birth * (like 1985-03-27)', true, 15],
        'account_no'  => ['Loan / account number *', true, 15],
        'branch_code' => ['Branch code', false, 9],
        'amount'      => ['Amount still owing (N$) *', true, 13],
        'default_date' => ['Date it went into default * (like 2026-08-01)', true, 17],
        'status'      => ['Default status *', true, 16],
        'employer'    => ['Employer', false, 20],
        'occupation'  => ['Occupation', false, 16],
        'cell'        => ['Cellphone', false, 14],
        'home_tel'    => ['Home telephone', false, 14],
        'work_tel'    => ['Work telephone', false, 14],
        'postal1'     => ['Postal address line 1 (like PO Box 2105)', false, 22],
        'postal2'     => ['Postal address line 2 (town)', false, 16],
        'postal_code' => ['Postal code', false, 9],
        'res1'        => ['Residential address line 1', false, 22],
        'res2'        => ['Residential address line 2 (town)', false, 16],
        'res_code'    => ['Residential postal code', false, 10],
        'work1'       => ['Work address', false, 22],
        'work_code'   => ['Work postal code', false, 9],
    ];

    /** What staff see in the "Default status" list -> the vendor key used by CreditinfoPublicDefaultFileService::DEFAULT_STATUS_CODES. */
    public const STATUSES = [
        'Handed Over' => 'HandedOver',
        'Collection' => 'Collection',
        'Write Off' => 'WriteOff',
        'Paid' => 'Paid',
        'Delinquent' => 'Delinquent',
        'Slow Payer' => 'SlowPayer',
        'Absconded' => 'Absconded',
        'Not Contactable' => 'NotContactable',
    ];

    /** Columns that hold codes or phone numbers, where a leading zero matters. */
    private const TEXT_KEYS = ['cell', 'home_tel', 'work_tel', 'branch_code', 'postal_code', 'res_code', 'work_code', 'account_no', 'id_number'];

    private const HEADER = '1F4E79';
    private const INPUT = 'FFF9D6';

    public function write(string $path, string $generatedOn): void
    {
        $book = new Spreadsheet();
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $this->howTo($book->getActiveSheet(), $generatedOn);

        $s = $book->createSheet();
        $s->setTitle(self::DATA_SHEET);
        $n = count(self::COLUMNS);
        $checkCol = $this->col($n);
        $lastCol = $this->col($n - 1);
        $last = self::ROWS + 1;

        $i = 0;
        foreach (self::COLUMNS as $key => [$head, $required, $width]) {
            $c = $this->col($i);
            $s->setCellValue($c . '1', $head);
            $s->getColumnDimension($c)->setWidth($width);
            $i++;
        }
        $s->setCellValue($checkCol . '1', 'Check (automatic)');
        $s->getColumnDimension($checkCol)->setWidth(34);

        $s->getStyle('A1:' . $checkCol . '1')->getFont()->setBold(true)->setColor(new Color('FFFFFF'));
        $s->getStyle('A1:' . $checkCol . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER);
        $s->getStyle('A1:' . $checkCol . '1')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getRowDimension(1)->setRowHeight(64);

        $s->getStyle('A2:' . $lastCol . $last)->getNumberFormat()->setFormatCode('@'); // text: IDs, phone numbers and dates keep exactly what is typed
        $s->getStyle('A2:' . $lastCol . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::INPUT);
        $s->getStyle('A2:' . $lastCol . $last)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $s->getStyle('A1:' . $checkCol . $last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C5CBD3');
        $s->getStyle('A2:' . $checkCol . $last)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        $letter = [];
        $i = 0;
        foreach (self::COLUMNS as $key => $_) {
            $letter[$key] = $this->col($i++);
        }
        $requiredKeys = array_keys(array_filter(self::COLUMNS, fn ($c) => $c[1]));
        for ($r = 2; $r <= $last; $r++) {
            $missing = implode(',', array_map(fn ($k) => $letter[$k] . $r . '=""', $requiredKeys));
            $s->setCellValue($checkCol . $r, '=IF(COUNTA(A' . $r . ':' . $lastCol . $r . ')=0,"",IF(OR(' . $missing . '),"Fill in every column marked *","OK"))');

            $this->list($s, $letter['id_type'] . $r, '"National ID,Passport"', 'ID type', 'Choose National ID or Passport from the list.');
            $this->list($s, $letter['status'] . $r, '"' . implode(',', array_keys(self::STATUSES)) . '"', 'Default status', 'Choose the status from the list.');
            foreach (['dob', 'default_date'] as $k) {
                $x = $letter[$k] . $r;
                $v = $s->getCell($x)->getDataValidation();
                $v->setType(DataValidation::TYPE_CUSTOM)->setFormula1('=AND(LEN(' . $x . ')=10,MID(' . $x . ',5,1)="-",MID(' . $x . ',8,1)="-",ISNUMBER(--SUBSTITUTE(' . $x . ',"-","")),--MID(' . $x . ',6,2)>=1,--MID(' . $x . ',6,2)<=12,--MID(' . $x . ',9,2)>=1,--MID(' . $x . ',9,2)<=31)')
                    ->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle('Date')->setError('Write the date like 2026-08-01 (year-month-day, with dashes).');
            }
            $x = $letter['amount'] . $r;
            $v = $s->getCell($x)->getDataValidation();
            $v->setType(DataValidation::TYPE_CUSTOM)->setFormula1('=AND(ISNUMBER(--' . $x . '),--' . $x . '>0)')->setAllowBlank(true)->setShowErrorMessage(true)
                ->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle('Amount')->setError('Type the amount as a number only, like 4500 (no N$, no spaces).');
        }

        $ok = new Conditional();
        $ok->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('LEFT(' . $checkCol . '2,2)="OK"');
        $ok->getStyle()->getFont()->getColor()->setRGB('0B6B2E');
        $ok->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('D6F2DD');
        $bad = new Conditional();
        $bad->setConditionType(Conditional::CONDITION_EXPRESSION)->addCondition('LEFT(' . $checkCol . '2,4)="Fill"');
        $bad->getStyle()->getFont()->getColor()->setRGB('9B1C1C');
        $bad->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB('FBD9D9');
        $s->getStyle($checkCol . '2:' . $checkCol . $last)->setConditionalStyles([$ok, $bad]);
        $s->getStyle($checkCol . '2:' . $checkCol . $last)->getFont()->setBold(true);

        $s->freezePane('D2');
        $s->setAutoFilter('A1:' . $checkCol . $last);
        $s->setSelectedCell('A2');
        $s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0)->setFitToPage(true);
        $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        $s->getProtection()->setSheet(true)->setAutoFilter(false)->setSort(false)->setFormatColumns(false)->setFormatRows(false);

        $book->setActiveSheetIndex(1);
        (new Xlsx($book))->save($path);
    }

    /** @return array<int, array<string,string>> one array per sheet row, keyed by the COLUMNS keys (cell text exactly as typed) */
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
        $at = [];
        foreach (self::COLUMNS as $key => [$h]) {
            if (!isset($col[strtolower($h)])) {
                throw new \RuntimeException('The sheet has no column headed "' . $h . '": use the template exactly as it was given out.');
            }
            $at[$key] = $col[strtolower($h)];
        }
        $rows = [];
        foreach ($grid as $r) {
            $row = [];
            foreach ($at as $key => $i) {
                $v = $r[$i] ?? '';
                if (in_array($key, ['dob', 'default_date'], true) && (is_int($v) || is_float($v)) && $v > 0 && $v < 100000) {
                    $v = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d'); // a real Excel date cell
                } elseif (is_float($v) || is_int($v)) {
                    if (in_array($key, self::TEXT_KEYS, true)) {
                        $row['_numeric'][] = $key; // a number cell where text was expected: any leading zeros are already gone
                    }
                    $v = is_float($v) ? rtrim(rtrim(sprintf('%.2F', $v), '0'), '.') : (string) $v;
                } else {
                    $v = (string) $v;
                }
                $row[$key] = $v;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<int, array<string,string>> $rows readFile() output
     * @return array{records: array<int, array<string,mixed>>, rejected: array<int, array{row:int, account_no:string, name:string, why:string}>, warnings: array<int, array{row:int, account_no:string, note:string}>}
     */
    public function parse(array $rows, string $today, string $defaultBranch = ''): array
    {
        $out = ['records' => [], 'rejected' => [], 'warnings' => []];
        $count = ['acct' => [], 'id' => []];
        foreach ($rows as $row) {
            $a = $this->tidy($row['account_no'] ?? '');
            if ($a !== '') {
                $count['acct'][strtolower($a)] = ($count['acct'][strtolower($a)] ?? 0) + 1;
            }
        }

        $codes = CreditinfoPublicDefaultFileService::DEFAULT_STATUS_CODES;
        $statusByName = [];
        foreach (self::STATUSES as $label => $key) {
            $statusByName[$this->squash($label)] = $key;
        }

        foreach (array_values($rows) as $i => $row) {
            $line = $i + 2;
            $any = false;
            foreach (self::COLUMNS as $key => $_) {
                if ($this->tidy($row[$key] ?? '') !== '') {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                continue;
            }
            $acct = $this->tidy($row['account_no'] ?? '');
            $name = trim($this->tidy($row['first_name'] ?? '') . ' ' . $this->tidy($row['surname'] ?? ''));
            $reject = function (string $why) use (&$out, $line, $acct, $name): void {
                $out['rejected'][] = ['row' => $line, 'account_no' => $acct, 'name' => $name, 'why' => $why];
            };

            $missing = [];
            foreach (self::COLUMNS as $key => [$head, $required]) {
                if ($required && $this->tidy($row[$key] ?? '') === '') {
                    $missing[] = rtrim(preg_replace('/\s*\(.*$/', '', $head) ?? $head, ' *');
                }
            }
            if ($missing) {
                $reject('missing: ' . implode(', ', $missing));
                continue;
            }
            if ($count['acct'][strtolower($acct)] > 1) {
                $reject('this loan / account number is on more than one row: keep one row for it');
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9\-\/]{1,30}$/', $acct)) {
                $reject('the loan / account number may only hold letters, digits, - and / (no spaces)');
                continue;
            }

            // identification
            $typeText = $this->squash($row['id_type']);
            $rawId = $this->tidy($row['id_number']);
            if (in_array($typeText, ['nationalid', 'nid', 'id'], true)) {
                $idType = CreditinfoPublicDefaultFileService::ID_TYPE_NATIONAL_ID;
                $id = preg_replace('/[\s\-]/', '', $rawId) ?? '';
                if (!preg_match('/^\d{11}$/', $id)) {
                    $reject('a National ID number is exactly 11 digits (got "' . $rawId . '"): fix it, or choose Passport for a foreign national');
                    continue;
                }
            } elseif ($typeText === 'passport') {
                $idType = CreditinfoPublicDefaultFileService::ID_TYPE_PASSPORT;
                $id = strtoupper(preg_replace('/\s+/', '', $rawId) ?? '');
                if (!preg_match('/^[A-Z0-9]{5,15}$/', $id)) {
                    $reject('a passport number is 5 to 15 letters and digits (got "' . $rawId . '")');
                    continue;
                }
            } else {
                $reject('"ID type" must be National ID or Passport');
                continue;
            }

            $dob = $this->parseDate($row['dob']);
            $defaultDate = $this->parseDate($row['default_date']);
            if ($dob === null) {
                $reject('the date of birth must be a real date like 1985-03-27');
                continue;
            }
            if ($defaultDate === null) {
                $reject('the date it went into default must be a real date like 2026-08-01');
                continue;
            }
            if ($defaultDate > $today) {
                $reject('the default date is in the future');
                continue;
            }
            if ($dob >= $defaultDate) {
                $reject('the date of birth must be before the default date');
                continue;
            }
            // UTC on purpose: in Africa/Windhoek the clock offset changed between years, which made an exact 18th birthday come out as 17
            $utc = new \DateTimeZone('UTC');
            $age = (int) (new \DateTimeImmutable($dob, $utc))->diff(new \DateTimeImmutable($defaultDate, $utc))->y;
            if ($age < 18 || $age > 100) {
                $reject('the customer would have been ' . $age . ' at the default date: check the date of birth');
                continue;
            }
            if ($idType === CreditinfoPublicDefaultFileService::ID_TYPE_NATIONAL_ID) {
                $fromId = substr($id, 0, 6);
                $fromDob = substr($dob, 2, 2) . substr($dob, 5, 2) . substr($dob, 8, 2);
                if ($fromId !== $fromDob) {
                    $out['warnings'][] = ['row' => $line, 'account_no' => $acct, 'note' => 'the date of birth (' . $dob . ') does not match the first six digits of the ID number (' . $fromId . '): confirm both are right'];
                }
            }

            // money
            $amountText = preg_replace('/(?i)n\$|nad|\s/', '', $this->tidy($row['amount'])) ?? '';
            if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $amountText)) {
                $amountText = str_replace(',', '', $amountText);
            }
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountText) || (float) $amountText <= 0) {
                $reject('the amount owing must be digits only, like 4500 or 4500.50 (a dot for cents, never a comma)');
                continue;
            }
            $amount = (float) $amountText;
            if (round($amount) < 1) {
                $reject('the amount owing is less than N$ 1: the file only carries whole dollars');
                continue;
            }
            if ($amount > 200000) {
                $reject('the amount owing is unbelievably large (over N$ 200 000): check it');
                continue;
            }
            if (round($amount) != $amount) {
                $out['warnings'][] = ['row' => $line, 'account_no' => $acct, 'note' => 'cents are dropped in the file: N$ ' . $amountText . ' is listed as N$ ' . (int) round($amount)];
            }

            // status
            $statusKey = $statusByName[$this->squash($row['status'])] ?? null;
            if ($statusKey === null) {
                $reject('"Default status" must be one of: ' . implode(', ', array_keys(self::STATUSES)));
                continue;
            }

            // optional bits: wrong values are refused, empty ones just stay empty
            $phones = [];
            $badPhone = null;
            foreach (['cell' => 'cellphone', 'home_tel' => 'home telephone', 'work_tel' => 'work telephone'] as $k => $label) {
                $raw = $this->tidy($row[$k] ?? '');
                $digits = preg_replace('/\D/', '', $raw) ?? '';
                if ($raw !== '' && (strlen($digits) < 7 || strlen($digits) > 15)) {
                    $badPhone = $label . ' "' . $raw . '" must have 7 to 15 digits';
                    break;
                }
                $phones[$k] = $digits;
            }
            if ($badPhone !== null) {
                $reject($badPhone);
                continue;
            }
            $typedBranch = $this->tidy($row['branch_code'] ?? '');
            $branch = $typedBranch !== '' ? $typedBranch : $defaultBranch;
            if ($branch === '') {
                $reject('no branch code: type it in the "Branch code" column, or build the file with the default branch code');
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $branch)) {
                $reject('the branch code may only hold letters and digits');
                continue;
            }

            if (!empty($row['_numeric'])) {
                $out['warnings'][] = ['row' => $line, 'account_no' => $acct, 'note' => 'these cells were saved as numbers, so leading zeros may be lost: ' . implode(', ', array_unique($row['_numeric'])) . ' (retype them as text if they start with 0)'];
            }
            $out['records'][] = [
                'row' => $line, 'id_type' => $idType, 'id_number' => $id, 'dob' => $dob,
                'first_name' => $this->tidy($row['first_name']), 'second_name' => $this->tidy($row['second_name'] ?? ''), 'surname' => $this->tidy($row['surname']),
                'branch_code' => $branch, 'account_no' => $acct, 'amount' => $amount, 'default_date' => $defaultDate, 'status_code' => $codes[$statusKey],
                'employer' => $this->tidy($row['employer'] ?? ''), 'occupation' => $this->tidy($row['occupation'] ?? ''),
                'cell' => $phones['cell'], 'home_tel' => $phones['home_tel'], 'work_tel' => $phones['work_tel'],
                'postal1' => $this->tidy($row['postal1'] ?? ''), 'postal2' => $this->tidy($row['postal2'] ?? ''), 'postal_code' => $this->tidy($row['postal_code'] ?? ''),
                'res1' => $this->tidy($row['res1'] ?? ''), 'res2' => $this->tidy($row['res2'] ?? ''), 'res_code' => $this->tidy($row['res_code'] ?? ''),
                'work1' => $this->tidy($row['work1'] ?? ''), 'work_code' => $this->tidy($row['work_code'] ?? ''),
            ];
        }

        // the same person on several accepted rows is allowed (two loans) but confirmed; the same account twice was refused above
        $byId = [];
        foreach ($out['records'] as $rec) {
            $byId[$rec['id_number']][] = $rec['account_no'];
        }
        foreach ($byId as $id => $accounts) {
            if (count($accounts) > 1) {
                $out['warnings'][] = ['row' => 0, 'account_no' => implode(', ', $accounts), 'note' => 'the same ID number is listed for more than one account: confirm these are separate loans'];
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string,mixed>> $records parse()['records']
     * @return string[] the file lines, in sheet order
     */
    public function build(array $records, string $supplierReferenceNumber, \DateTimeImmutable $transactionDate, ?CreditinfoPublicDefaultFileService $files = null): array
    {
        $files ??= new CreditinfoPublicDefaultFileService();
        $lines = [];
        foreach ($records as $rec) {
            $lines[] = $files->renderRecord($rec, $supplierReferenceNumber, CreditinfoPublicDefaultFileService::ACTION_LISTING, $transactionDate);
        }
        return $lines;
    }

    private function tidy(string $v): string
    {
        return trim(preg_replace('/[\p{Z}\x{200B}]+/u', ' ', $v) ?? $v);
    }

    private function squash(string $v): string
    {
        return strtolower(preg_replace('/[^a-z]/i', '', $v) ?? '');
    }

    /** Only YYYY-MM-DD (real Excel date cells are turned into it by readFile): a day/month order is never guessed. */
    private function parseDate(string $raw): ?string
    {
        $raw = $this->tidy($raw);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        return checkdate($mo, $d, $y) && $y >= 1900 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    private function col(int $zeroBased): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($zeroBased + 1);
    }

    private function list(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s, string $cell, string $formula, string $title, string $error): void
    {
        $v = $s->getCell($cell)->getDataValidation();
        $v->setType(DataValidation::TYPE_LIST)->setFormula1($formula)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true) /* the writer inverts this flag: true = the arrow IS shown in Excel */
            ->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle($title)->setError($error);
    }

    private function howTo(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $how, string $generatedOn): void
    {
        $how->setTitle('How to fill in');
        $how->setShowGridlines(false);
        $how->getColumnDimension('A')->setWidth(4);
        $how->getColumnDimension('B')->setWidth(110);
        $how->setCellValue('B1', 'Customers to list as defaulters at Creditinfo');
        $how->getStyle('B1')->getFont()->setBold(true)->setSize(20)->setColor(new Color(self::HEADER));
        $how->setCellValue('B2', 'Listing template  -  made ' . $generatedOn);
        $how->getStyle('B2')->getFont()->setSize(12)->setColor(new Color('52606D'));
        $how->setCellValue('B4', 'Fill in one row for each loan that is to be listed. We check every row, then turn the good rows into the file Creditinfo accepts. Nothing is sent to Creditinfo from this sheet.');
        $how->getStyle('B4')->getAlignment()->setWrapText(true);
        $how->getRowDimension(4)->setRowHeight(34);

        $steps = [
            ['STEP 1', 'Open the sheet "' . self::DATA_SHEET . '" (tab at the bottom). One row = one loan in default. All the yellow cells are for you to fill in.'],
            ['STEP 2', 'Columns marked with * must be filled in. The others (employer, phones, addresses) are optional but help Creditinfo find the right person: fill in what you know.'],
            ['STEP 3', 'ID type: choose National ID (exactly 11 digits) or Passport (foreign nationals). Dates are written year-month-day with dashes, like 2026-08-01.'],
            ['STEP 4', '"Amount still owing" is what the customer owes now, in N$, numbers only (like 4500). "Date it went into default" is the day the account became a default.'],
            ['STEP 5', '"Default status": choose from the list. Handed Over = given to a collector or lawyer; Collection = being collected; Write Off = written off as a loss; Delinquent / Slow Payer = badly late; Absconded = customer ran away; Not Contactable = cannot be reached; Paid = fully paid.'],
            ['STEP 6', 'The last column "Check" turns GREEN with OK when the required cells are filled. We check everything else (ID length, dates, amount) when the file is built and tell you which rows to fix.'],
            ['STEP 7', 'Save the file (keep it an .xlsx Excel file) and send it back as it is.'],
        ];
        $row = 6;
        foreach ($steps as [$tag, $text]) {
            $how->setCellValue('B' . $row, $tag . '   ' . $text);
            $how->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $how->getRowDimension($row)->setRowHeight(48);
            $row++;
        }
        $how->setCellValue('B14', 'Good to know');
        $how->getStyle('B14')->getFont()->setBold(true)->setSize(13);
        $notes = [
            'Do not delete columns or change the headings: the headings are how we read your answers.',
            'Listing a customer at a credit bureau affects them seriously. Only list a loan that is really in default and whose details you have checked.',
            'This file contains customer names, ID numbers and phone numbers: send it only to the people who need it, and delete it when finished.',
        ];
        $row = 15;
        foreach ($notes as $text) {
            $how->setCellValue('B' . $row, '-  ' . $text);
            $how->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $how->getRowDimension($row)->setRowHeight(34);
            $row++;
        }
        $how->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(1)->setFitToPage(true);
    }
}
