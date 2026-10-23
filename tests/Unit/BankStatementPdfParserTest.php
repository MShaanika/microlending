<?php

namespace Tests\Unit;

use App\Services\AiBankStatementRowsReader;
use App\Services\BankStatementPdfParser;
use App\Services\BankStatementPdfReader;
use PHPUnit\Framework\TestCase;

/**
 * Text fixtures are invented: they copy the SHAPE of the real FNB Namibia and Bank Windhoek statement text, never real customers' lines.
 */
class BankStatementPdfParserTest extends TestCase
{
    private function fnbLines(): array
    {
        return [
            'First National Bank of Namibia Ltd',
            'www.fnbnamibia.com.na',
            'SWIFT Code FIRNNANX',
            'Statement Period : 15 December 2025 to14 January 2026',
            "Opening Balance\t100.00Cr",
            "Closing Balance\t640.00Cr",
            "Transactions in NAMIBIA DOLLARS (NAD)",
            "Date\tDescription\tAmount Balance",
            "20 DecSalary Acme Ltd\t500.00Cr 600.00Cr",
            "28 DecSend Money Dr Send 264810000001\t50.00 550.00Cr 12.00",
            "03 JanAirtime Topup Airtime 264810000002\t10.00 540.00Cr 2.60",
            "12 JanCash Withdrawal Windhoek\t600.00 60.00Dr",
            "14 JanFnb App Transfer From Someone\t700.00Cr 640.00Cr",
            "Closing Balance\t640.00Cr",
        ];
    }

    private function bankWindhoekLines(): array
    {
        return [
            'Bank Windhoek Ltd.',
            'PERIODIC STATEMENT',
            '31/05/2025',
            '01/05/2025 to 31/05/2025',
            "CHARGES BROUGHT FORWARD\t0.00",
            "OPENING BALANCE\t100.00",
            "01/05/2025CARDFEEMAY25**1111\t7.50\t92.50",
            '02/05/2025GOODS AND SERVIC (**1111 - 30/04/2025)|5411',
            "SOME SHOP WINDHOEK       \t12.50\t80.00",
            "05/05/2025SALARY\t500.00 580.00",
            "06/05/2025ATM WITHDRAWAL FEES\t12.19\t567.81",
            'Page 2 of 4',
        ];
    }

    public function testFnbIsRecognisedAndReadLineByLine(): void
    {
        $r = BankStatementPdfParser::parseLines($this->fnbLines());
        $this->assertSame('FNB Namibia', $r['bank']);
        $this->assertSame([], $r['errors']);
        $this->assertCount(5, $r['rows']);
        $this->assertSame(100.0, $r['opening']);
        $this->assertSame(640.0, $r['closing']);

        $salary = $r['rows'][0];
        $this->assertSame('2025-12-20', $salary['transaction_date']);
        $this->assertSame('Salary Acme Ltd', $salary['description']);
        $this->assertSame(500.0, $salary['money_in']);
        $this->assertSame(0.0, $salary['money_out']);
        $this->assertSame(600.0, $salary['balance']);

        $debit = $r['rows'][1];
        $this->assertSame(0.0, $debit['money_in'], 'no Cr suffix means money out');
        $this->assertSame(50.0, $debit['money_out']);
        $this->assertSame(550.0, $debit['balance'], 'the trailing accrued-charges figure is not the balance');
    }

    public function testFnbYearIsTakenFromThePeriodAcrossNewYear(): void
    {
        $rows = BankStatementPdfParser::parseLines($this->fnbLines())['rows'];
        $this->assertSame('2025-12-28', $rows[1]['transaction_date']);
        $this->assertSame('2026-01-03', $rows[2]['transaction_date']);
        $this->assertSame('2026-01-14', $rows[4]['transaction_date']);
    }

    public function testFnbOverdrawnBalanceIsNegative(): void
    {
        $rows = BankStatementPdfParser::parseLines($this->fnbLines())['rows'];
        $this->assertSame(-60.0, $rows[3]['balance']);
        $check = BankStatementPdfParser::verify($rows, 100.0, 640.0);
        $this->assertTrue($check['balanced']);
        $this->assertTrue($check['closing_matches']);
    }

    public function testBankWindhoekDirectionComesFromTheBalanceMovement(): void
    {
        $r = BankStatementPdfParser::parseLines($this->bankWindhoekLines());
        $this->assertSame('Bank Windhoek', $r['bank']);
        $this->assertSame([], $r['errors'], 'header dates before the opening balance must not be read as transactions');
        $this->assertCount(4, $r['rows']);

        $this->assertSame(7.5, $r['rows'][0]['money_out']);
        $this->assertSame(0.0, $r['rows'][0]['money_in']);
        $this->assertSame(500.0, $r['rows'][2]['money_in'], 'the balance went up, so it is money in');
        $this->assertSame(0.0, $r['rows'][2]['money_out']);
    }

    public function testBankWindhoekWrappedNarrationIsJoined(): void
    {
        $row = BankStatementPdfParser::parseLines($this->bankWindhoekLines())['rows'][1];
        $this->assertSame('2025-05-02', $row['transaction_date']);
        $this->assertSame('GOODS AND SERVIC (**1111 - 30/04/2025)|5411 SOME SHOP WINDHOEK', $row['description']);
        $this->assertSame(12.5, $row['money_out']);
        $this->assertSame(80.0, $row['balance']);
    }

    public function testAStatementThatAddsUpIsBalanced(): void
    {
        $r = BankStatementPdfParser::parseLines($this->bankWindhoekLines());
        $check = BankStatementPdfParser::verify($r['rows'], $r['opening'], $r['closing']);
        $this->assertTrue($check['balanced']);
        $this->assertSame([], $check['broken']);
        $this->assertSame(500.0, $check['total_in']);
        $this->assertSame(32.19, $check['total_out']);
    }

    public function testAMisreadAmountBreaksTheCheck(): void
    {
        $lines = $this->fnbLines();
        $lines[9] = "28 DecSend Money Dr Send 264810000001\t5.00 550.00Cr 12.00"; // 5.00 instead of 50.00
        $r = BankStatementPdfParser::parseLines($lines);
        $check = BankStatementPdfParser::verify($r['rows'], $r['opening'], $r['closing']);
        $this->assertFalse($check['balanced']);
        $this->assertSame([1], $check['broken'], 'the line whose amount does not explain its balance is pointed out');
    }

    public function testAWrongClosingBalanceBreaksTheCheck(): void
    {
        $r = BankStatementPdfParser::parseLines($this->fnbLines());
        $check = BankStatementPdfParser::verify($r['rows'], $r['opening'], 999.99);
        $this->assertFalse($check['balanced']);
        $this->assertFalse($check['closing_matches']);
    }

    public function testMissingOpeningBalanceIsNotBalanced(): void
    {
        $r = BankStatementPdfParser::parseLines($this->fnbLines());
        $check = BankStatementPdfParser::verify($r['rows'], null, null);
        $this->assertFalse($check['balanced'], 'without an opening balance nothing can be proven');
    }

    public function testAnUnreadableTransactionLineIsReportedNotDropped(): void
    {
        $lines = $this->fnbLines();
        $lines[] = '15 JanSomething odd with no amounts at all';
        $r = BankStatementPdfParser::parseLines($lines);
        $this->assertCount(5, $r['rows']);
        $this->assertCount(1, $r['errors']);
        $this->assertStringContainsString('Something odd', $r['errors'][0]);
    }

    public function testIdenticalLinesOnTheSameDayStayDistinctButARepeatImportMatches(): void
    {
        $lines = $this->fnbLines();
        array_splice($lines, 9, 0, ["28 DecSend Money Dr Send 264810000001\t50.00 500.00Cr 12.00"]); // same date/amount, lower balance
        $first = BankStatementPdfParser::parseLines($lines)['rows'];
        $again = BankStatementPdfParser::parseLines($lines)['rows'];
        $this->assertNotSame($first[1]['reference_no'], $first[2]['reference_no'], 'different balance, so a different fingerprint');
        $this->assertSame($first[1]['reference_no'], $again[1]['reference_no'], 'the same line always gets the same fingerprint');
    }

    public function testAnUnknownBankIsRefusedPlainly(): void
    {
        $r = BankStatementPdfParser::parseLines(['Some Other Bank', '01/01/2025 SOMETHING 10.00 90.00']);
        $this->assertSame([], $r['rows']);
        $this->assertStringContainsString('not recognised', $r['errors'][0]);
    }

    // --- AI backup reader: pure normalisation of the model's JSON --------------------------------------------------------

    public function testAiRowsAreCheckedAndGapsInBalancesAreFilled(): void
    {
        $out = AiBankStatementRowsReader::normalise([
            'opening_balance' => 100.0,
            'closing_balance' => 90.0,
            'transactions' => [
                ['date' => '2026-01-05', 'description' => 'Deposit', 'money_in' => 50.0, 'money_out' => 0, 'balance' => null],
                ['date' => '2026-01-06', 'description' => 'Fee', 'money_in' => 0, 'money_out' => 60.0, 'balance' => null],
                ['date' => 'not a date', 'description' => 'Junk', 'money_in' => 1, 'money_out' => 0, 'balance' => 1],
                ['date' => '2026-01-07', 'description' => 'Both columns', 'money_in' => 5, 'money_out' => 5, 'balance' => 1],
            ],
        ]);
        $this->assertTrue($out['success']);
        $this->assertCount(2, $out['rows']);
        $this->assertSame(2, $out['skipped']);
        $this->assertSame(150.0, $out['rows'][0]['balance']);
        $this->assertSame(90.0, $out['rows'][1]['balance']);
        $this->assertTrue(BankStatementPdfParser::verify($out['rows'], $out['opening'], $out['closing'])['balanced']);
    }

    public function testAiReturningNothingUsableFails(): void
    {
        $out = AiBankStatementRowsReader::normalise(['opening_balance' => null, 'closing_balance' => null, 'transactions' => [
            ['date' => '2026-01-07', 'description' => 'zero', 'money_in' => 0, 'money_out' => 0, 'balance' => null],
        ]]);
        $this->assertFalse($out['success']);
    }

    // --- review copy -----------------------------------------------------------------------------------------------------

    public function testPreviewBelongsToTheUserWhoUploadedIt(): void
    {
        if (!defined('STORAGE_PATH')) {
            $this->markTestSkipped('STORAGE_PATH is not defined in this bootstrap.');
        }
        $token = BankStatementPdfReader::savePreview(['user_id' => 7, 'rows' => [], 'bank_account_id' => 1]);
        try {
            $this->assertNotNull(BankStatementPdfReader::loadPreview($token, 7));
            $this->assertNull(BankStatementPdfReader::loadPreview($token, 8), 'another user cannot open it');
            $this->assertNull(BankStatementPdfReader::loadPreview($token, 0));
            $this->assertNull(BankStatementPdfReader::loadPreview('../../etc/passwd', 7), 'tokens are hex only');
        } finally {
            BankStatementPdfReader::deletePreview($token);
        }
        $this->assertNull(BankStatementPdfReader::loadPreview($token, 7), 'gone after deletion');
    }

    // --- the real PDF pipeline, with a PDF generated on the spot ----------------------------------------------------------

    public function testARealPdfIsReadEndToEnd(): void
    {
        if (!BankStatementPdfParser::isAvailable() || !class_exists(\Dompdf\Dompdf::class)) {
            $this->markTestSkipped('smalot/pdfparser or dompdf is not installed.');
        }
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; font-size: 10px">';
        foreach ($this->bankWindhoekLines() as $line) {
            $html .= '<p style="margin:0">' . htmlspecialchars(str_replace("\t", ' ', $line)) . '</p>';
        }
        $html .= '</body></html>';
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->render();
        $path = tempnam(sys_get_temp_dir(), 'stmt') . '.pdf';
        file_put_contents($path, $dompdf->output());

        try {
            $r = BankStatementPdfParser::parseFile($path);
        } finally {
            @unlink($path);
        }
        $this->assertSame('Bank Windhoek', $r['bank']);
        $this->assertCount(4, $r['rows']);
        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
    }

    public function testANonPdfIsRefused(): void
    {
        if (!BankStatementPdfParser::isAvailable()) {
            $this->markTestSkipped('smalot/pdfparser is not installed.');
        }
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, 'this is not a pdf');
        try {
            $r = BankStatementPdfParser::parseFile($path);
        } finally {
            @unlink($path);
        }
        $this->assertFalse($r['ok']);
        $this->assertSame([], $r['rows']);
    }
}
