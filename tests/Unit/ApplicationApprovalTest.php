<?php

namespace Tests\Unit;

use App\Services\ApplicationApprovalSheet;
use App\Services\ApplicationLoanBook;
use App\Services\CplRecordBuilder;
use App\Services\CplValidationService;
use App\Services\LegacyCplExtract;
use App\Services\LegacyCplMapper;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The online applications -> staff sheet (approved? amount, date paid out) -> checked answers -> the monthly CPL file.
 */
class ApplicationApprovalTest extends TestCase
{
    private PDO $pdo;
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/app_sheet_' . uniqid();
        mkdir($this->dir);
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE loan_applications (id INTEGER PRIMARY KEY, application_no TEXT, applicant_first_name TEXT, applicant_middle_name TEXT, applicant_last_name TEXT,
            applicant_id_number TEXT, applicant_gender TEXT, applicant_phone TEXT, applicant_address TEXT, employer_name TEXT, gross_salary REAL, payment_day INT, requested_amount REAL,
            requested_term_months INT, status TEXT, created_at TEXT)');
        $ins = $this->pdo->prepare('INSERT INTO loan_applications VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([1, 'APP-1', 'Maria', '', 'Test', '85051500123', 'Female', '0811111111', 'Erf 1 Test Street', 'Employer A', 9000, 25, 1000, 1, 'Submitted', '2026-10-30 09:00:00']);
        $ins->execute([2, 'APP-2', 'Paul', 'J', 'Two', '90010100456', 'Male', '0822222222', 'Erf 2 Test Street', 'Employer B', 8000, 20, 6000, 5, 'Submitted', '2026-10-29 09:00:00']);
        $ins->execute([3, 'APP-3', 'Festus', '', 'Tester', '', 'Male', '0833333333', 'Erf 3', 'X', 0, 25, 500, 1, 'Submitted', '2026-10-31 09:00:00']);
        $ins->execute([4, 'APP-4', 'Old', '', 'Approved', '80010100789', 'Male', '0844444444', 'Erf 4', 'Y', 5000, 25, 2000, 2, 'Approved', '2026-09-01 09:00:00']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function byNo(): array
    {
        $out = [];
        foreach (ApplicationLoanBook::fetch($this->pdo, null) as $a) {
            $out[$a['application_no']] = $a;
        }
        return $out;
    }

    private function row(string $no, string $approved, string $amount = '', string $paid = ''): array
    {
        return ['application_no' => $no, 'approved' => $approved, 'amount' => $amount, 'paid_out' => $paid];
    }

    private function parse(array $rows, string $asOf = '2026-10-31'): array
    {
        return (new ApplicationApprovalSheet())->parse($rows, $this->byNo(), $asOf, '2026-11-17');
    }

    public function testFetchFiltersByStatusAndLeavesTestApplicantsOut(): void
    {
        $all = ApplicationLoanBook::fetch($this->pdo, 'Submitted', ['festus  TESTER']);

        $this->assertSame(['APP-2', 'APP-1'], array_column($all, 'application_no'), 'in the order they were made');
        $this->assertSame(['APP-4'], array_column(ApplicationLoanBook::fetch($this->pdo, null, [], ['APP-4']), 'application_no'));
        $this->assertSame([], ApplicationLoanBook::fetch($this->pdo, null, [], []));
    }

    public function testApprovedRowsNeedAnAmountAndADateAndEverythingElseIsExplained(): void
    {
        $r = $this->parse([
            $this->row('APP-1', 'Yes', 'N$ 1,000', '2026-10-30'),
            $this->row('APP-2', 'no'),
            $this->row('APP-3', ''),
            $this->row('APP-4', 'maybe'),
            $this->row('APP-9', 'Yes', '500', '2026-10-30'),
        ]);

        $this->assertSame(['APP-1' => ['amount' => 1000.0, 'paid_out' => '2026-10-30']], $r['approved']);
        $this->assertSame(1, $r['declined']);
        $this->assertSame(1, $r['unanswered']);
        $this->assertSame([5, 6], array_column($r['rejected'], 'row'));
        $this->assertStringContainsString('Yes or No', $r['rejected'][0]['why']);
        $this->assertStringContainsString('not on the list', $r['rejected'][1]['why']);
    }

    public function testBadAmountsAndDatesAreRefused(): void
    {
        $r = $this->parse([
            $this->row('APP-1', 'Yes', '', '2026-10-30'),
            $this->row('APP-2', 'Yes', 'abc', '2026-10-30'),
            $this->row('APP-3', 'Yes', '0', '2026-10-31'),
            $this->row('APP-4', 'Yes', '999999', '2026-10-30'),
            $this->row('APP-1', 'Yes', '1000', '2026-10-30'),
        ]);

        $this->assertSame([2, 3, 4, 5, 6], array_column($r['rejected'], 'row'));
        $this->assertSame([], $r['approved']);
        $this->assertStringContainsString('more than one row', $r['rejected'][4]['why']);
    }

    public function testDatesBeforeTheApplicationOrInTheFutureAreRefusedAndLaterMonthsAreSetAside(): void
    {
        $r = $this->parse([
            $this->row('APP-1', 'Yes', '1000', '2026-10-29'), // applied on the 30th
            $this->row('APP-2', 'Yes', '6000', '2026-12-01'), // future (today is 17 Nov)
            $this->row('APP-4', 'Yes', '2000', '2026-11-10'), // fine, but after the month end
        ]);

        $this->assertSame([2, 3], array_column($r['rejected'], 'row'));
        $this->assertStringContainsString('before the application', $r['rejected'][0]['why']);
        $this->assertStringContainsString('future', $r['rejected'][1]['why']);
        $this->assertSame(['APP-4'], array_keys($r['approved']));
        $this->assertSame(['APP-4'], $r['later']);
    }

    public function testAnAmountAboveTheAmountAskedForIsAWarningNotAnError(): void
    {
        $r = $this->parse([$this->row('APP-1', 'Yes', '1500', '2026-10-30')]);

        $this->assertArrayHasKey('APP-1', $r['approved']);
        $this->assertStringContainsString('more than', $r['warnings'][0]['note']);
    }

    public function testTheSheetRoundTripsWithTextAmountsAndDateCells(): void
    {
        $path = $this->dir . '/sheet.xlsx';
        $sheet = new ApplicationApprovalSheet();
        $sheet->write(ApplicationLoanBook::fetch($this->pdo, 'Submitted'), $path, '2026-10-31');

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $s = $book->getSheetByName(ApplicationApprovalSheet::DATA_SHEET);
        $this->assertSame('APP-2', (string) $s->getCell('A2')->getValue());
        $this->assertSame('Paul J Two', (string) $s->getCell('B2')->getValue());
        $text = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
        $s->setCellValueExplicit('L2', 'Yes', $text);
        $s->setCellValue('M2', 6000);
        $s->setCellValue('N2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2026-10-30')));
        $s->setCellValueExplicit('L3', 'No', $text);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $parsed = $this->parse($sheet->readFile($path));

        $this->assertSame(['APP-2' => ['amount' => 6000.0, 'paid_out' => '2026-10-30']], $parsed['approved']);
        $this->assertSame(1, $parsed['declined']);
        $this->assertSame([], $parsed['rejected']);
    }

    public function testASheetWithoutTheExpectedHeadingsIsRefused(): void
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray([['a', 'b'], [1, 2]]);
        $path = $this->dir . '/other.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        $this->expectException(\RuntimeException::class);
        (new ApplicationApprovalSheet())->readFile($path);
    }

    private function build(array $rows, string $asOf = '2026-10-31'): array
    {
        $parsed = $this->parse($rows, $asOf);
        $byNo = $this->byNo();
        $approved = [];
        foreach ($parsed['approved'] as $no => $a) {
            $approved[] = ['application' => $byNo[$no], 'amount' => $a['amount'], 'paid_out' => $a['paid_out']];
        }
        return (new LegacyCplExtract(new ApplicationLoanBook($approved), new LegacyCplMapper(), new CplValidationService(), new CplRecordBuilder()))
            ->monthly($asOf, 'NA02629', 'Solid Desert Test');
    }

    public function testTheFileHasOneRecordPerApprovedLoanPaidOutByTheMonthEndWithTheApplicationNumberAsAccount(): void
    {
        $result = $this->build([
            $this->row('APP-1', 'Yes', '1000', '2026-10-30'),
            $this->row('APP-2', 'Yes', '6000', '2026-10-29'),
            $this->row('APP-4', 'Yes', '2000', '2026-11-10'),
        ]);

        $this->assertSame(2, $result['summary']['sent']);
        $this->assertSame(0, $result['summary']['blocked']);
        $this->assertSame(['released after the as-of date' => 1], $result['excluded']);
        $this->assertStringContainsString('APP-1', $result['file']);
        $this->assertStringContainsString('APP-2', $result['file']);
        $this->assertStringContainsString('85051500123', $result['file']);
        // 1000 + 5 fee + 10.30 levy = 1015.30; +30% = 1319.89 -> 1320 owed on a one-month loan; nothing is due yet
        $this->assertSame(1320, $result['details'][0]['opening_balance']);
        $this->assertSame(1320, $result['details'][0]['current_balance']);
        $this->assertSame(0, $result['details'][0]['amount_overdue']);
    }

    public function testAnApplicationWithoutAnIdCannotBeSentAndIsListedByItsApplicationNumber(): void
    {
        $result = $this->build([$this->row('APP-3', 'Yes', '500', '2026-10-31')]);

        $this->assertSame(0, $result['summary']['sent']);
        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame('APP-3', $result['exceptions'][0]['ref_no']);
    }

    public function testALoanWhoseFirstInstalmentWasAlreadyDueAtTheMonthEndIsRefusedBecauseRepaymentsAreUnknown(): void
    {
        // pay day 25, paid out 2 October: due 25 October, before the month end
        $r = $this->parse([$this->row('APP-4', 'Yes', '2000', '2026-10-02')]);

        $this->assertSame([], $r['approved']);
        $this->assertStringContainsString('fell due on 2026-10-25', $r['rejected'][0]['why']);
    }

    public function testThePayDayIsCappedAt28LikeTheMapperDoes(): void
    {
        $this->assertSame('2026-11-28', ApplicationLoanBook::firstInstalmentDate('2026-10-29', 30), 'a pay-out on the 29th with pay day 30 (28) falls in November');
        $this->assertSame('2026-10-28', ApplicationLoanBook::firstInstalmentDate('2026-10-02', 30));
        $this->assertSame('2026-11-25', ApplicationLoanBook::firstInstalmentDate('2026-10-30', 25));
        $this->assertSame('2027-01-20', ApplicationLoanBook::firstInstalmentDate('2026-12-20', 20));
        $this->assertSame('2026-10-25', ApplicationLoanBook::firstInstalmentDate('2026-10-02', 0), 'an unknown pay day counts as the 25th');
    }

    public function testACommaIsOnlyEverAThousandsSeparator(): void
    {
        $ok = $this->parse([$this->row('APP-1', 'Yes', '1,500', '2026-10-30'), $this->row('APP-2', 'Yes', '6 000.50', '2026-10-30')]);
        $this->assertSame([1500.0, 6000.5], array_column($ok['approved'], 'amount'));

        $bad = $this->parse([$this->row('APP-1', 'Yes', '4,50', '2026-10-30'), $this->row('APP-2', 'Yes', '4500,50', '2026-10-30'), $this->row('APP-4', 'Yes', '1.500,00', '2026-10-30')]);
        $this->assertSame([], $bad['approved']);
        $this->assertCount(3, $bad['rejected']);
        $this->assertStringContainsString('never a comma', $bad['rejected'][0]['why']);
    }

    public function testOnlyIsoDatesAreAcceptedNeverADayMonthGuess(): void
    {
        $r = $this->parse([$this->row('APP-1', 'Yes', '1000', '03/11/2026'), $this->row('APP-2', 'Yes', '6000', '2026-10-30')]);

        $this->assertSame(['APP-2'], array_keys($r['approved']));
        $this->assertStringContainsString('real date', $r['rejected'][0]['why']);
    }

    public function testTwoApprovedApplicationsForTheSamePersonAreFlagged(): void
    {
        $this->pdo->exec("UPDATE loan_applications SET applicant_id_number = '85051500123' WHERE id = 2");
        $r = $this->parse([$this->row('APP-1', 'Yes', '1000', '2026-10-30'), $this->row('APP-2', 'Yes', '6000', '2026-10-30')]);

        $this->assertCount(2, $r['approved']);
        $this->assertStringContainsString('same ID number', $r['warnings'][0]['note']);
    }
}
