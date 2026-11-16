<?php

namespace Tests\Unit;

use App\Services\CplRecordBuilder;
use App\Services\CplValidationService;
use App\Services\LegacyCplExtract;
use App\Services\LegacyCplMapper;
use App\Services\LegacyLoanBookReader;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The whole path for the loan book still kept in the old loan system: a small copy of its tables (in memory) -> reader -> mapper -> validator -> file.
 */
class LegacyCplExtractTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([
            'CREATE TABLE loan_plan (id INTEGER PRIMARY KEY, months INT, interest_percentage REAL)',
            'CREATE TABLE borrowers (id INTEGER PRIMARY KEY, firstname TEXT, middlename TEXT, lastname TEXT, id_number TEXT, identity TEXT, gender TEXT, contact_no TEXT, address TEXT, company_name TEXT, gross_salary REAL, payment_day TEXT)',
            'CREATE TABLE loan_list (id INTEGER PRIMARY KEY, ref_no TEXT, borrower_id INT, amount REAL, plan_id INT, status INT, date_released TEXT, date_written_off TEXT)',
            'CREATE TABLE payments (id INTEGER PRIMARY KEY, loan_id INT, amount REAL, date_created TEXT, transaction_tpye TEXT)',
            'CREATE TABLE loan_schedules (id INTEGER PRIMARY KEY, loan_id INT, date_due TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO loan_plan VALUES (3, 3, 30), (1, 1, 30)");
    }

    private function borrower(int $id, string $first, string $last, string $idNumber): void
    {
        $this->pdo->prepare('INSERT INTO borrowers VALUES (?, ?, "", ?, ?, "", "Male", "0811111111", "Erf 1 Test Street", "Test Employer", 5000, "25")')->execute([$id, $first, $last, $idNumber]);
    }

    private function loan(int $id, int $borrower, int $status, string $released, string $writtenOff = '0000-00-00', int $plan = 3): void
    {
        $this->pdo->prepare('INSERT INTO loan_list VALUES (?, ?, ?, 1000, ?, ?, ?, ?)')->execute([$id, 'SDLOAN1', $borrower, $plan, $status, $released, $writtenOff]);
        $this->pdo->prepare('INSERT INTO loan_schedules (loan_id, date_due) VALUES (?, ?)')->execute([$id, '2026-09-00']);
    }

    private function pay(int $loan, float $amount, string $date, string $type = 'Loan Repayment'): void
    {
        $this->pdo->prepare('INSERT INTO payments (loan_id, amount, date_created, transaction_tpye) VALUES (?, ?, ?, ?)')->execute([$loan, $amount, $date, $type]);
    }

    private function extract(): array
    {
        return (new LegacyCplExtract(new LegacyLoanBookReader($this->pdo), new LegacyCplMapper(), new CplValidationService(), new CplRecordBuilder()))
            ->monthly('2026-10-31', 'NA02629', 'Solid Desert Test');
    }

    private function book(): void
    {
        $this->borrower(1, 'Maria', 'One', '85051500123');
        $this->borrower(2, 'Peter', 'Two', '');
        $this->borrower(3, 'Anna', 'Three', '90010100123');
        $this->loan(10, 1, 2, '2026-08-20 10:00:00');                        // active, paying
        $this->pay(10, 440, '2026-09-25 10:00:00');
        $this->pay(10, 440, '2026-10-25 10:00:00');
        $this->loan(11, 2, 2, '2026-08-20 10:00:00');                        // active but no ID: blocked
        $this->loan(12, 3, 5, '2026-01-10 10:00:00', '2026-03-28');          // written off
        $this->loan(13, 1, 3, '2026-08-01 10:00:00');                        // completed this month
        $this->pay(13, 1319.89, '2026-10-12 10:00:00');
        $this->loan(14, 1, 3, '2025-08-01 10:00:00');                        // completed long ago: left out
        $this->pay(14, 1319.89, '2025-09-12 10:00:00');
        $this->loan(15, 1, 2, '2026-11-05 10:00:00');                        // released after the month end: left out
        $this->loan(16, 1, 0, '0000-00-00 00:00:00');                        // a request: left out
        $this->pay(10, 99, '2026-10-26 10:00:00', 'Fee Collection');         // not a repayment
    }

    public function testTheFileHasAHeaderEveryAcceptedRecordAndATrailer(): void
    {
        $this->book();

        $r = $this->extract();
        $lines = explode("\r\n", $r['file']);

        $this->assertSame(3, $r['records'], 'loans 10, 12 and 13');
        $this->assertCount(5, $lines, 'header + 3 records + trailer');
        foreach ($lines as $line) {
            $this->assertSame(700, strlen($line));
        }
        $this->assertSame('H', $lines[0][0]);
        $this->assertSame('T', $lines[4][0]);
        $this->assertSame('000000005', substr($lines[4], 1, 9), 'the trailer counts the header and itself');
        $this->assertStringContainsString('NA02629', $lines[0]);
        $this->assertSame('D', $lines[1][0]);
    }

    public function testAccountsThatCannotBeSentAreListedWithWhy(): void
    {
        $this->book();

        $r = $this->extract();

        $this->assertCount(1, $r['exceptions']);
        $this->assertSame(11, $r['exceptions'][0]['loan_id']);
        $this->assertSame('SDL0000011', $r['exceptions'][0]['account_no']);
        $this->assertStringContainsString('na_id', $r['exceptions'][0]['reasons'][0]);
        $this->assertContains('no ID number', $r['exceptions'][0]['notes']);
    }

    public function testWhatWasLeftOutIsCountedByReason(): void
    {
        $this->book();

        $this->assertSame([
            'closed before this month' => 1,
            'released after the as-of date' => 1,
        ], $this->extract()['excluded']);
    }

    public function testRepaymentsAreOnlyLoanRepaymentsNotFeesOrDisbursements(): void
    {
        $this->book();

        $line = explode("\r\n", $this->extract()['file'])[1];

        $this->assertSame('20261025', substr($line, 385, 8), 'date of last payment: the Fee Collection of 26 October is not a repayment');
    }

    public function testTheSummaryGivesTotalsToCheckAgainstTheOldSystemsScreens(): void
    {
        $this->book();

        $s = $this->extract()['summary'];

        $this->assertSame('2026-10-31', $s['as_of']);
        $this->assertSame(3, $s['sent']);
        $this->assertSame(1, $s['blocked']);
        $this->assertSame(['active' => 1, 'C' => 1, 'W' => 1], $s['by_status']);
        $this->assertSame(3 * 1320, $s['opening_balance']);
        $this->assertSame(440 + 1320, $s['current_balance'], 'active 439.89 -> 440, closed 0, written off 1,320');
        $this->assertSame(0, $s['amount_overdue']);
        $this->assertSame(0, $s['accounts_in_arrears']);
    }

    public function testAnEmptyBookStillGivesAValidEmptyFile(): void
    {
        $r = $this->extract();

        $this->assertSame(0, $r['records']);
        $this->assertCount(2, explode("\r\n", $r['file']));
        $this->assertSame('000000002', substr(explode("\r\n", $r['file'])[1], 1, 9));
    }

    public function testTheReaderOnlyEverIssuesSelects(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/LegacyLoanBookReader.php');

        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE)\b\s/i', preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $source));
    }
}
