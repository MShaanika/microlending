<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Loan;
use App\Models\StatutoryCharge;
use App\Services\LoanReleaseService;
use App\Services\LoanScheduleService;
use App\Services\LoanStatementExcelExporter;
use App\Services\LoanStatementPdfExporter;
use App\Services\LoanStatementService;
use App\Services\LoanStatementSummary;
use PHPUnit\Framework\TestCase;

/**
 * The loan from the client's statement LN-261124-5735DD: 100.00 paid out, NAMFISA levy 1.03, stamp duty 5.00, interest 31.81,
 * one month, 137.84 to repay. The schedule, the transaction history and the loan summary must tell ONE story on every output:
 * schedule total due == history closing balance == total payable, and opening balance = principal + levy + stamp = 106.03.
 * Runs against the local dev database with a throw-away loan (removed afterwards, with every journal it caused).
 */
class LoanStatementReconciliationTest extends TestCase
{
    private \PDO $db;
    private array $loanIds = [];
    private int $borrowerId;
    private int $branchId;
    private int $productId;
    private int $planId;
    private int $userId;
    private ?array $savedSession = null;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $b = $this->db->query('SELECT id, branch_id FROM borrowers ORDER BY id LIMIT 1')->fetch();
        $p = $this->db->query('SELECT p.id AS product_id, l.id AS plan_id FROM loan_products p JOIN loan_plans l ON l.product_id = p.id ORDER BY p.id LIMIT 1')->fetch();
        $this->borrowerId = (int) $b['id'];
        $this->branchId = (int) $b['branch_id'];
        $this->productId = (int) $p['product_id'];
        $this->planId = (int) $p['plan_id'];
        $this->userId = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->savedSession = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->loanIds) as $id) {
            // the levy/stamp rows point at the release journal, so they go first
            foreach (['namfisa_levy_transactions', 'duty_stamp_transactions'] as $table) {
                $this->db->prepare("DELETE FROM $table WHERE loan_id = ?")->execute([$id]);
            }
            $journalIds = $this->col("SELECT id FROM accounting_journal_entries WHERE source_table = 'loans' AND source_id = ?", [$id]);
            if ($journalIds) {
                $all = implode(',', array_map('intval', $journalIds));
                $this->db->exec("UPDATE accounting_journal_entries SET reversed_from = NULL WHERE id IN ($all)");
                $this->db->exec("DELETE FROM accounting_journal_lines WHERE journal_id IN ($all)");
                $this->db->exec("DELETE FROM accounting_journal_entries WHERE id IN ($all)");
            }
            foreach (['interest_accruals', 'namfisa_levy_transactions', 'duty_stamp_transactions', 'loan_disbursements', 'loan_status_history', 'loan_schedules'] as $table) {
                $this->db->prepare("DELETE FROM $table WHERE loan_id = ?")->execute([$id]);
            }
            $this->db->prepare('DELETE FROM loans WHERE id = ?')->execute([$id]);
        }
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
    }

    private function col(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** The statement's loan, created the way LoanController::store() creates it, still Approved (not paid out). */
    private function approvedLoanWithLevyAndStamp(): int
    {
        $schedule = LoanScheduleService::generate(100.00, 1, 30, 0, 'Flat', '2026-11-24', 1.03, 5.00, null, '2026-11-30');
        $this->assertSame(31.81, $schedule['interest_amount']);
        $this->assertSame(137.84, $schedule['total_payable']);

        $loans = new Loan();
        $id = $loans->create([
            'branch_id' => $this->branchId, 'borrower_id' => $this->borrowerId, 'product_id' => $this->productId, 'plan_id' => $this->planId,
            'loan_no' => 'PHPUNIT-STMT-' . uniqid(), 'loan_type' => 'New Loan', 'principal_amount' => 100.00,
            'interest_amount' => $schedule['interest_amount'], 'admin_fee' => 0, 'total_payable' => $schedule['total_payable'],
            'installment_amount' => $schedule['installment_amount'], 'term_months' => 1, 'interest_rate' => 30,
            'interest_recognition_method' => 'Upfront', 'penalty_rate' => 5, 'loan_status' => 'Approved', 'approval_status' => 'Approved',
            'start_date' => '2026-11-24', 'maturity_date' => '2026-11-30',
        ]);
        $this->loanIds[] = $id;
        $loans->insertScheduleRows($id, $schedule['rows']);
        $charges = new StatutoryCharge();
        $charges->recordNamfisaLevy(['loan_id' => $id, 'borrower_id' => $this->borrowerId, 'branch_id' => $this->branchId, 'levy_date' => '2026-11-24',
            'levy_rate' => 1.03, 'basis_amount' => 100.00, 'levy_amount' => $schedule['namfisa_levy'], 'status' => 'Calculated']);
        $charges->recordDutyStamp(['loan_id' => $id, 'borrower_id' => $this->borrowerId, 'branch_id' => $this->branchId, 'stamp_date' => '2026-11-24',
            'basis_amount' => 100.00, 'stamp_amount' => $schedule['duty_stamp'], 'status' => 'Calculated']);
        return $id;
    }

    private function releaseManually(int $id): void
    {
        $loans = new Loan();
        $loan = $loans->find($id);
        $loans->transaction(fn () => (new LoanReleaseService())->release($id, $loan, $this->userId, null, 'Cash', 'BANKREF-STMT'));
    }

    /** @return array{loan: array, schedule: array, ledger: array, summary: array} */
    private function statement(int $id): array
    {
        $loans = new Loan();
        $loan = $loans->find($id);
        $schedule = $loans->schedule($id);
        $ledger = LoanStatementService::ledger($id);
        return ['loan' => $loan, 'schedule' => $schedule, 'ledger' => $ledger, 'summary' => LoanStatementSummary::forLoan($loan, $schedule, $ledger)];
    }

    private function borrower(): array
    {
        return ['first_name' => 'Test', 'last_name' => 'Client', 'borrower_no' => 'BRW-TEST', 'phone' => '0810000000', 'physical_address' => ''];
    }

    public function testScheduleHistoryAndTotalPayableAgreeForALoanWithLevyAndStamp(): void
    {
        $id = $this->approvedLoanWithLevyAndStamp();
        $this->releaseManually($id);
        $st = $this->statement($id);

        // the schedule: levy + stamp inside the principal, no separate fee
        $this->assertSame(106.03, round((float) $st['schedule'][0]['opening_balance'], 2));
        $this->assertSame(106.03, round(array_sum(array_column($st['schedule'], 'principal_due')), 2));
        $this->assertSame(0.0, round(array_sum(array_column($st['schedule'], 'fees_due')), 2));
        $scheduleDue = round(array_sum(array_column($st['schedule'], 'total_due')), 2);

        // the history: payout, the two charges ADDED to it (reaching the same 106.03), then interest -- no "Fee" line
        $this->assertSame(['Disbursed', 'Charge', 'Charge', 'Interest'], array_column($st['ledger']['events'], 'type'));
        $this->assertSame([100.0, 101.03, 106.03, 137.84], array_column($st['ledger']['events'], 'balance'));
        $this->assertSame('Amount Disbursed - Ref BANKREF-STMT', $st['ledger']['events'][0]['description']);
        $this->assertSame('NAMFISA levy (1.03%) added to the loan balance', $st['ledger']['events'][1]['description']);
        $this->assertSame(106.03, $st['ledger']['opening_balance'], 'the history opens at the schedule\'s opening balance');

        // the reconciliation
        $this->assertSame(137.84, $scheduleDue);
        $this->assertSame($scheduleDue, $st['ledger']['closing_balance'], 'history closing balance == schedule total due');
        $this->assertSame($scheduleDue, round((float) $st['loan']['total_payable'], 2), 'schedule total due == total payable');

        $s = $st['summary'];
        $this->assertSame(100.0, $s['principal']);
        $this->assertSame(1.03, $s['levy']);
        $this->assertSame(5.0, $s['stamp']);
        $this->assertSame(6.03, $s['charges']);
        $this->assertSame(106.03, $s['opening_balance']);
        $this->assertSame(137.84, $s['total_payable']);
        $this->assertSame(137.84, $s['outstanding']);
        $this->assertSame(date('Y-m-d'), $s['disbursed_on']);
        $this->assertTrue($s['paid_out']);
        $this->assertSame([], $s['problems'], 'nothing disagrees');
    }

    public function testBeforeThePayoutNothingIsChargedOrOwed(): void
    {
        $id = $this->approvedLoanWithLevyAndStamp();
        $st = $this->statement($id);

        $this->assertSame([], $st['ledger']['events'], 'no "Amount Disbursed" line for money that never left');
        $this->assertStringContainsString('not been paid out yet', $st['ledger']['notice']);
        $this->assertFalse($st['summary']['paid_out']);
        $this->assertSame(0.0, $st['summary']['outstanding']);
        $this->assertSame(106.03, $st['summary']['opening_balance'], 'the plan still shows principal + charges');
        $this->assertSame([], $st['summary']['problems']);
        $this->assertStringStartsWith('Nothing is owed yet', LoanStatementSummary::stillToPayLine($st['summary']));

        // the printed statement does not say anything was paid out or added
        $company = ['company_name' => 'Test Co', 'address' => '', 'email' => '', 'phone' => '', 'registration_no' => ''];
        $html = LoanStatementPdfExporter::html($st['loan'], $this->borrower(), $st['schedule'], $st['ledger'], $company, $st['summary']);
        $this->assertStringContainsString('Principal (to be paid out): 100.00', $html);
        $this->assertStringContainsString('Opening balance (once paid out): 106.03', $html);
        $this->assertStringNotContainsString('(paid out)', $html);
        $this->assertStringNotContainsString('100.00 paid out', $html);
        $this->assertStringNotContainsString('admin fee', $html, 'this loan has no admin fee');
    }

    public function testThePdfShowsTheSameFiguresAndATotalsRowThatLinesUp(): void
    {
        $id = $this->approvedLoanWithLevyAndStamp();
        $this->releaseManually($id);
        $st = $this->statement($id);
        $company = ['company_name' => 'Test Co', 'address' => '', 'email' => '', 'phone' => '', 'registration_no' => ''];

        $html = LoanStatementPdfExporter::html($st['loan'], $this->borrower(), $st['schedule'], $st['ledger'], $company, $st['summary']);

        $this->assertStringContainsString('<h4>Client</h4>', $html);
        $this->assertStringNotContainsString('Borrower', $html);
        $this->assertStringContainsString('Principal (paid out): 100.00', $html);
        $this->assertStringContainsString('+ NAMFISA levy: 1.03', $html);
        $this->assertStringContainsString('+ Stamp duty: 5.00', $html);
        $this->assertStringContainsString('Opening balance: 106.03', $html);
        $this->assertStringContainsString('Total payable: 137.84', $html);
        $this->assertStringContainsString('Still to pay: 137.84', $html);
        $this->assertStringContainsString('<th>Closing Principal</th>', $html);
        $this->assertStringContainsString('Still to pay (Total Due - Paid): 137.84', $html);
        $this->assertStringContainsString('includes the NAMFISA levy 1.03 and stamp duty 5.00 added to the 100.00 paid out', $html);

        // the Total row: Principal, Interest, Penalty, Fees, Total Due, Paid -- and NOTHING under Closing Principal / Status
        $this->assertSame(1, preg_match('#<td colspan="3" class="r">Total</td>(.*?)</tr>#s', $html, $m));
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $m[1], $cells);
        $this->assertSame(['106.03', '31.81', '0.00', '0.00', '137.84', '0.00', '', ''], array_map('trim', $cells[1]));

        // the PDF itself still renders
        $pdf = LoanStatementPdfExporter::build($st['loan'], $this->borrower(), $st['schedule'], $st['ledger'], $company, $st['summary']);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testTheExcelFileShowsTheSameFigures(): void
    {
        $id = $this->approvedLoanWithLevyAndStamp();
        $this->releaseManually($id);
        $st = $this->statement($id);

        $sheet = LoanStatementExcelExporter::build($st['loan'], $this->borrower(), $st['schedule'], $st['ledger'], null, $st['summary'])->getActiveSheet();
        $byLabel = [];
        $headerRow = $totalRow = $closingRow = null;
        foreach ($sheet->toArray(null, false, false, true) as $r => $cells) {
            if (is_string($cells['A']) && $cells['C'] !== null) {
                $byLabel[$cells['A']] = $cells['C'];
            }
            if ($cells['C'] === 'Opening Principal') {
                $headerRow = $cells;
            }
            if ($cells['B'] === 'Total') {
                $totalRow = $cells;
            }
            if ($cells['C'] === 'Closing Balance') {
                $closingRow = $cells;
            }
        }
        $this->assertEquals(100.00, $byLabel['Principal (paid out)']);
        $this->assertEquals(6.03, round($byLabel['+ NAMFISA levy'] + $byLabel['+ Stamp duty'], 2));
        $this->assertEquals(106.03, $byLabel['Opening balance']);
        $this->assertEquals(137.84, $byLabel['Total payable']);
        $this->assertEquals(137.84, $byLabel['Still to pay']);
        $this->assertSame('Closing Principal', $headerRow['J']);
        $this->assertEquals(137.84, $totalRow['H'], 'Total Due');
        $this->assertEquals(0.0, $totalRow['I'], 'Paid');
        $this->assertNull($totalRow['J'], 'nothing summed under Closing Principal');
        $this->assertEquals(137.84, $closingRow['F'], 'history closing balance');
    }

    public function testTheOnScreenStatementShowsTheSameFigures(): void
    {
        $id = $this->approvedLoanWithLevyAndStamp();
        $this->releaseManually($id);
        $st = $this->statement($id);
        $company = ['company_name' => 'Test Co', 'address' => '', 'email' => '', 'phone' => '', 'registration_no' => ''];

        foreach (['loans/statement.php', 'portal/loans/invoice.php'] as $view) {
            $html = $this->render($view, [
                'title' => 'Statement', 'loan' => $st['loan'], 'schedule' => $st['schedule'], 'borrower' => $this->borrower(),
                'company' => $company, 'ledger' => $st['ledger'], 'summary' => $st['summary'],
            ]);
            $this->assertStringContainsString('<h5>Client</h5>', $html, $view);
            $this->assertStringContainsString('Opening balance: 106.03', $html, $view);
            $this->assertStringContainsString('Total payable: 137.84', $html, $view);
            $this->assertStringContainsString('Still to pay (Total Due - Paid): 137.84', $html, $view);
            $this->assertStringContainsString('<th>Closing Principal</th>', $html, $view);
            $this->assertStringContainsString('Stamp duty added to the loan balance', $html, $view);
            $this->assertStringNotContainsString('These figures do not agree', $html, $view);
        }
    }

    public function testTheLoanPageShowsTheSameFiguresAndThePayoutDate(): void
    {
        $id = $this->approvedLoanWithLevyAndStamp();
        $this->releaseManually($id);
        // as on LN-261124-5735DD: the start date typed in is the first due date, after the day the money left
        $this->db->prepare("UPDATE loans SET start_date = '2099-11-30' WHERE id = ?")->execute([$id]);
        $st = $this->statement($id);
        $loans = new Loan();

        $html = $this->render('loans/show.php.content', [
            'combined' => null, 'summary' => $st['summary'], 'title' => 'Loan', 'loan' => $st['loan'], 'encrOffer' => null,
            'outOfStep' => null, 'collectionCheck' => null, 'disbursement' => $loans->activeDisbursement($id), 'schedule' => $st['schedule'],
            'bankAccounts' => [], 'originalLoan' => null, 'topups' => [], 'latestTopup' => null, 'latestTopupReversible' => false,
            'childTopupReversible' => false, 'hasReschedule' => false, 'namfisaLevy' => 1.03, 'dutyStamp' => 5.00, 'statusHistory' => [],
        ]);

        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('Principal (paid out): 100.00', $text);
        $this->assertStringContainsString('+ NAMFISA levy: 1.03', $text);
        $this->assertStringContainsString('+ Stamp duty: 5.00', $text);
        $this->assertStringContainsString('Opening balance: 106.03', $text);
        $this->assertStringContainsString('Total Payable: 137.84', $text);
        $this->assertStringContainsString('Paid out on: ' . date('Y-m-d'), $text);
        $this->assertStringContainsString('start date is after the payout', $text);
        $this->assertStringContainsString('Current Outstanding Balance: 137.84', $text);
        $this->assertStringContainsString('Closing Principal', $text);
        $this->assertStringNotContainsString('These figures do not agree', $text);
    }

    public function testAFigureThatDoesNotAgreeIsReported(): void
    {
        $id = $this->approvedLoanWithLevyAndStamp();
        $this->releaseManually($id);
        // a schedule changed behind the history's back (as a reschedule does): the history no longer matches
        $this->db->prepare('UPDATE loan_schedules SET interest_due = interest_due + 10, total_due = total_due + 10 WHERE loan_id = ?')->execute([$id]);
        $st = $this->statement($id);

        $this->assertNotSame([], $st['summary']['problems']);
        $this->assertStringContainsString('The transaction history ends at 137.84 but the schedule says 147.84 is still to pay', $st['summary']['problems'][0]);
    }

    private function render(string $view, array $data): string
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })(VIEW_PATH . '/' . $view, $data);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            restore_error_handler();
        }
    }
}
