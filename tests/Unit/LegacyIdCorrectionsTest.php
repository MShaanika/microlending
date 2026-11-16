<?php

namespace Tests\Unit;

use App\Services\CplRecordBuilder;
use App\Services\CplValidationService;
use App\Services\LegacyCplExtract;
use App\Services\LegacyCplMapper;
use App\Services\LegacyIdCorrections;
use App\Services\LegacyLoanBookReader;
use App\Services\LegacyStaffSheet;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The staff sheet and the way its answers come back: written, filled in, read, checked, and laid over the file that is built.
 */
class LegacyIdCorrectionsTest extends TestCase
{
    private const TODAY = '2026-11-16';
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/legacy_sheet_' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function row(string $borrower, string $id, string $dob = ''): array
    {
        return ['borrower_id' => $borrower, 'id_number' => $id, 'date_of_birth' => $dob];
    }

    public function testANamibianIdIsAcceptedWithSpacesRemovedAndTheDateOfBirthTakenFromIt(): void
    {
        $r = (new LegacyIdCorrections())->parse([$this->row('5', '850515 00123'), $this->row('6', '00 01 01 12345')], self::TODAY);

        $this->assertSame(['na_id' => '85051500123', 'non_na_id' => '', 'date_of_birth' => '19850515'], $r['applied'][5]);
        $this->assertSame('20000101', $r['applied'][6]['date_of_birth'], 'two-digit years up to this year are 20xx');
        $this->assertSame([], $r['rejected']);
    }

    public function testAnElevenDigitIdWithAnImpossibleDateIsRejectedNotSilentlyTreatedAsForeign(): void
    {
        $r = (new LegacyIdCorrections())->parse([$this->row('5', '85023000123')], self::TODAY);

        $this->assertSame([], $r['applied']);
        $this->assertSame(2, $r['rejected'][0]['row']);
        $this->assertStringContainsString('not a believable date of birth', $r['rejected'][0]['why']);
    }

    public function testAnotherIdNeedsAPlausibleDateOfBirthInAnyOfTheThreeFormats(): void
    {
        $r = (new LegacyIdCorrections())->parse([
            $this->row('1', '1234567', '1985-05-15'),
            $this->row('2', '2234567', '19850515'),
            $this->row('3', '3234567', '15/05/1985'),
            $this->row('4', '4234567', ''),
            $this->row('5', '5234567', '2015-01-01'),
            $this->row('6', '6234567', '1985-02-30'),
        ], self::TODAY);

        $this->assertSame([1, 2, 3], array_keys($r['applied']));
        $this->assertSame('19850515', $r['applied'][3]['date_of_birth']);
        $this->assertSame('1234567', $r['applied'][1]['non_na_id']);
        $this->assertSame([5, 6, 7], array_column($r['rejected'], 'row'), 'missing, under-age and impossible dates are all refused');
    }

    public function testLettersWrongLengthsDuplicatesAndDamagedCustomerNumbersAreRejectedWithAReason(): void
    {
        $r = (new LegacyIdCorrections())->parse([
            $this->row('1', 'N1234567', '1985-05-15'),
            $this->row('2', '1234', '1985-05-15'),
            $this->row('3', '12345678901234', '1985-05-15'),
            $this->row('4', '1234567', '1985-05-15'),
            $this->row('4', '7654321', '1985-05-15'),
            $this->row('abc', '1234567', '1985-05-15'),
        ], self::TODAY);

        $this->assertSame([4], array_keys($r['applied']));
        $this->assertSame([2, 3, 4, 6, 7], array_column($r['rejected'], 'row'));
        $this->assertStringContainsString('only contain digits', $r['rejected'][0]['why']);
        $this->assertStringContainsString('appears twice', $r['rejected'][3]['why']);
        $this->assertStringContainsString('customer number', $r['rejected'][4]['why']);
    }

    public function testEmptyAnswersAreCountedAsNotAnsweredAndBlankRowsIgnored(): void
    {
        $r = (new LegacyIdCorrections())->parse([$this->row('1', ''), $this->row('', ''), $this->row('2', '   ')], self::TODAY);

        $this->assertSame(2, $r['unanswered']);
        $this->assertSame([], $r['applied']);
        $this->assertSame([], $r['rejected']);
    }

    public function testTheSheetRoundTripsAndKeepsLeadingZerosAndTheCustomerNumber(): void
    {
        $path = $this->dir . '/sheet.xlsx';
        (new LegacyStaffSheet())->write([
            ['borrower_id' => 12, 'name' => 'Maria Test', 'phone' => '0811234567', 'loans' => 'A1 A2', 'problem' => 'no ID number', 'suggested_id' => '85051500123', 'confidence' => 'high', 'matched_with' => 'phone'],
            ['borrower_id' => 13, 'name' => 'Paul Two', 'phone' => '', 'loans' => 'B1', 'problem' => 'no ID number', 'suggested_id' => '', 'confidence' => '', 'matched_with' => ''],
        ], $path, '2026-10-31');

        // a person fills in the two yellow columns and saves
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $book->getSheetByName(LegacyStaffSheet::DATA_SHEET);
        $this->assertSame('12', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('85051500123', (string) $sheet->getCell('F2')->getValue());
        $sheet->setCellValueExplicit('H2', '85051500123', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('H3', '0001234567', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('I3', '1985-05-15', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $c = new LegacyIdCorrections();
        $rows = $c->readFile($path);
        $r = $c->parse($rows, self::TODAY);

        $this->assertCount(2, $rows);
        $this->assertSame('85051500123', $r['applied'][12]['na_id']);
        $this->assertSame('0001234567', $r['applied'][13]['non_na_id'], 'a leading zero survives');
        $this->assertSame('19850515', $r['applied'][13]['date_of_birth']);
    }

    public function testFutureAndUnderageNamibianIdsAreRefusedAndTheCenturyFallsBackToThe1900s(): void
    {
        $r = (new LegacyIdCorrections())->parse([$this->row('1', '26123112345'), $this->row('2', '15051512345'), $this->row('3', '45051512345')], self::TODAY);

        $this->assertSame([1 => '19261231', 3 => '19450515'], array_map(static fn ($a) => $a['date_of_birth'], $r['applied']));
        $this->assertSame([3], array_column($r['rejected'], 'row'), 'an 11-year-old (born 2015) is refused');
    }

    public function testTheSameIdForTwoCustomersRefusesBoth(): void
    {
        $r = (new LegacyIdCorrections())->parse([$this->row('1', '85051500123'), $this->row('2', '85051500123'), $this->row('3', '90010100456')], self::TODAY);

        $this->assertSame([3], array_keys($r['applied']));
        $this->assertCount(2, $r['rejected']);
        $this->assertStringContainsString('more than one customer', $r['rejected'][0]['why']);
    }

    public function testOnlyCustomersOnTheMissingListMayBeCorrected(): void
    {
        $c = new LegacyIdCorrections();
        $report = $c->restrictTo($c->parse([$this->row('1', '85051500123'), $this->row('2', '90010100456')], self::TODAY), [2]);

        $this->assertSame([2], array_keys($report['applied']));
        $this->assertStringContainsString('not on the missing-details list', $report['rejected'][0]['why']);
    }

    public function testNonBreakingSpacesAreIgnoredAndATenDigitNumberCellIsRefused(): void
    {
        $nbsp = "\u{00A0}";
        $r = (new LegacyIdCorrections())->parse([
            $this->row('1' . $nbsp, '85051500123' . $nbsp),
            ['borrower_id' => '2', 'id_number' => '5031512345', 'date_of_birth' => '', 'numeric_cell' => true],
        ], self::TODAY);

        $this->assertSame([1], array_keys($r['applied']));
        $this->assertStringContainsString('leading zero', $r['rejected'][0]['why']);
    }

    public function testASheetWithoutTheExpectedHeadingsIsRefused(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray([['a', 'b'], [1, 2]]);
        $path = $this->dir . '/other.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $this->expectException(\RuntimeException::class);
        (new LegacyIdCorrections())->readFile($path);
    }

    public function testAnExcelDateCellInTheDateOfBirthColumnIsUnderstood(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $s = $book->getActiveSheet();
        $s->setTitle(LegacyStaffSheet::DATA_SHEET);
        $s->fromArray([[LegacyStaffSheet::HEAD_BORROWER, LegacyStaffSheet::HEAD_ID, LegacyStaffSheet::HEAD_DOB]]);
        $s->setCellValue('A2', 7);
        $s->setCellValueExplicit('B2', '1234567', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $s->setCellValue('C2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('1985-05-15')));
        $path = $this->dir . '/dates.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $c = new LegacyIdCorrections();
        $r = $c->parse($c->readFile($path), self::TODAY);

        $this->assertSame('19850515', $r['applied'][7]['date_of_birth']);
    }

    public function testCorrectionsMakeABlockedAccountSendableAndAreCountedButTheOldSystemIsUntouched(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([
            'CREATE TABLE loan_plan (id INTEGER PRIMARY KEY, months INT, interest_percentage REAL)',
            'CREATE TABLE borrowers (id INTEGER PRIMARY KEY, firstname TEXT, middlename TEXT, lastname TEXT, id_number TEXT, identity TEXT, gender TEXT, contact_no TEXT, address TEXT, company_name TEXT, gross_salary REAL, payment_day TEXT)',
            'CREATE TABLE loan_list (id INTEGER PRIMARY KEY, ref_no TEXT, borrower_id INT, amount REAL, plan_id INT, status INT, date_released TEXT, date_written_off TEXT)',
            'CREATE TABLE payments (id INTEGER PRIMARY KEY, loan_id INT, amount REAL, date_created TEXT, transaction_tpye TEXT)',
            'CREATE TABLE loan_schedules (id INTEGER PRIMARY KEY, loan_id INT, date_due TEXT)',
            'INSERT INTO loan_plan VALUES (3, 3, 30)',
            "INSERT INTO borrowers VALUES (1, 'Maria', '', 'One', '', '', 'Female', '0811111111', 'Erf 1 Test Street', 'Employer', 5000, '25')",
            "INSERT INTO borrowers VALUES (2, 'Peter', '', 'Two', '', '', 'Male', '0822222222', 'Erf 2 Test Street', 'Employer', 5000, '25')",
            "INSERT INTO loan_list VALUES (1, 'L1', 1, 1000, 3, 2, '2026-09-01', '0000-00-00')",
            "INSERT INTO loan_list VALUES (2, 'L2', 2, 1000, 3, 2, '2026-09-01', '0000-00-00')",
            "INSERT INTO loan_schedules (loan_id, date_due) VALUES (1, '2026-10-00'), (2, '2026-10-00')",
        ] as $sql) {
            $pdo->exec($sql);
        }
        $extract = fn (array $corr) => (new LegacyCplExtract(new LegacyLoanBookReader($pdo), new LegacyCplMapper(), new CplValidationService(), new CplRecordBuilder()))
            ->monthly('2026-10-31', 'NA02629', 'Solid Desert Test', $corr);

        $before = $extract([]);
        $this->assertSame(2, $before['summary']['blocked']);

        $after = $extract([1 => ['na_id' => '85051500123', 'non_na_id' => '', 'date_of_birth' => '19850515']]);

        $this->assertSame(1, $after['summary']['sent']);
        $this->assertSame(1, $after['summary']['blocked']);
        $this->assertSame(1, $after['summary']['corrected_accounts']);
        $this->assertSame(2, (int) $after['exceptions'][0]['borrower_id']);
        $this->assertStringContainsString('85051500123', $after['file']);
        $this->assertSame('', (string) $pdo->query('SELECT id_number FROM borrowers WHERE id = 1')->fetchColumn(), 'the old system data is not changed');
    }
}
