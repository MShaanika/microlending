<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Borrower;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Models\Loan;
use App\Services\ArrearsService;
use App\Services\CollexiaApiException;
use App\Services\CollexiaEncrApiClient;
use App\Services\CollexiaEncrDisbursementService;
use App\Services\CplExporter;
use App\Services\DashboardService;
use App\Services\EncrUnpostService;
use App\Services\InterestAccrualService;
use App\Services\LoanReleaseService;
use App\Services\LoanStatementService;
use App\Services\PenaltyAccrualService;
use PHPUnit\Framework\TestCase;

/**
 * A loan released with a Collexia EnCr payout WAITS ("Awaiting Payout") and posts nothing until Collexia
 * reports the payout Completed; the manual release is unchanged. Runs against the local dev database with
 * throw-away loans (removed afterwards, with every journal they caused). Collexia itself is always mocked.
 */
class EncrAwaitingPayoutTest extends TestCase
{
    private \PDO $db;
    private array $loanIds = [];
    private array $batchJournalIds = [];
    private int $borrowerId;
    private int $branchId;
    private int $productId;
    private int $planId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        if (!CollexiaEncrDisbursementService::schemaReady()) {
            $this->markTestSkipped('database/encr_awaiting_payout.sql has not been applied to the local database.');
        }
        $b = $this->db->query('SELECT id, branch_id FROM borrowers ORDER BY id LIMIT 1')->fetch();
        $p = $this->db->query('SELECT p.id AS product_id, l.id AS plan_id FROM loan_products p JOIN loan_plans l ON l.product_id = p.id ORDER BY p.id LIMIT 1')->fetch();
        $this->borrowerId = (int) $b['id'];
        $this->branchId = (int) $b['branch_id'];
        $this->productId = (int) $p['product_id'];
        $this->planId = (int) $p['plan_id'];
        $this->userId = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->loanIds) as $id) {
            $this->db->prepare('DELETE FROM namfisa_levy_transactions WHERE loan_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM duty_stamp_transactions WHERE loan_id = ?')->execute([$id]);
            $journalIds = $this->col("SELECT id FROM accounting_journal_entries WHERE source_table = 'loans' AND source_id = ?", [$id]);
            if ($journalIds) {
                $in = implode(',', array_map('intval', $journalIds));
                $reversalIds = $this->col("SELECT id FROM accounting_journal_entries WHERE source_table = 'accounting_journal_entries' AND source_id IN ($in)");
                $all = implode(',', array_map('intval', array_merge($journalIds, $reversalIds)));
                $this->db->exec("UPDATE accounting_journal_entries SET reversed_from = NULL WHERE id IN ($all)");
                $this->db->exec("DELETE FROM accounting_journal_lines WHERE journal_id IN ($all)");
                $this->db->exec("DELETE FROM accounting_journal_entries WHERE id IN ($all)");
            }
            foreach (['interest_accruals', 'payments', 'agent_commissions', 'loan_disbursements', 'loan_status_history', 'loan_schedules'] as $table) {
                $this->db->prepare("DELETE FROM $table WHERE loan_id = ?")->execute([$id]);
            }
            $this->db->prepare('DELETE FROM loans WHERE id = ?')->execute([$id]);
        }
        // batch journals of the periodic interest accrual (source_table 'loan_schedules', no loan id), run for one test loan only
        if ($this->batchJournalIds) {
            $all = implode(',', array_map('intval', $this->batchJournalIds));
            $this->db->exec("DELETE FROM accounting_journal_lines WHERE journal_id IN ($all)");
            $this->db->exec("DELETE FROM accounting_journal_entries WHERE id IN ($all)");
        }
    }

    /** Runs the periodic interest accrual for ONE test loan and remembers the batch journal it posts, for tearDown. */
    private function accrueFor(int $loanId): void
    {
        $before = (int) $this->one('SELECT COALESCE(MAX(id), 0) FROM accounting_journal_entries');
        InterestAccrualService::accrue(date('Y-m-d'), $this->userId, $loanId);
        foreach ($this->col("SELECT id FROM accounting_journal_entries WHERE id > ? AND source_table = 'loan_schedules' AND source_module = 'INTEREST_ACCRUAL'", [$before]) as $jid) {
            $this->batchJournalIds[] = (int) $jid;
        }
    }

    /** Net Interest Receivable (1030) over the loan's own journals, their reversals and the remembered batch accrual journals. */
    private function interestReceivable(int $loanId): float
    {
        $batch = $this->batchJournalIds ? implode(',', array_map('intval', $this->batchJournalIds)) : '0';
        return round((float) $this->one(
            "SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM accounting_journal_lines l JOIN accounting_journal_entries e ON e.id = l.journal_id
             WHERE l.account_id = (SELECT id FROM accounting_accounts WHERE account_code = '1030')
               AND (e.id IN ($batch) OR e.source_table = 'loans' AND e.source_id = ? OR e.source_table = 'accounting_journal_entries' AND e.source_id IN
                    (SELECT id FROM (SELECT id FROM accounting_journal_entries WHERE source_table = 'loans' AND source_id = ?) x))", [$loanId, $loanId]), 2);
    }

    private function col(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function one(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** An Approved, Upfront-interest loan with an overdue first instalment (so arrears/penalty/accrual would pick it up if it counted). */
    private function approvedLoan(string $recognition = 'Upfront', array $dueDates = ['2020-02-01', '2020-03-01']): int
    {
        $this->db->prepare(
            "INSERT INTO loans (branch_id, borrower_id, product_id, plan_id, loan_no, principal_amount, interest_amount, interest_rate, interest_recognition_method, term_months,
                                installment_amount, total_payable, payment_day, loan_status, start_date, maturity_date, penalty_rate)
             VALUES (?, ?, ?, ?, ?, 1000, 300, 30, ?, 2, 650, 1300, 30, 'Approved', '2020-01-01', ?, 5)"
        )->execute([$this->branchId, $this->borrowerId, $this->productId, $this->planId, 'PHPUNIT-ENCR-' . uniqid(), $recognition, end($dueDates)]);
        $id = (int) $this->db->lastInsertId();
        $this->loanIds[] = $id;
        foreach ($dueDates as $i => $date) {
            $this->db->prepare(
                "INSERT INTO loan_schedules (loan_id, installment_no, due_date, principal_due, principal_paid, interest_due, interest_paid, total_due, total_paid, status)
                 VALUES (?, ?, ?, 500, 0, 150, 0, 650, 0, 'Pending')"
            )->execute([$id, $i + 1, $date]);
        }
        return $id;
    }

    private function release(int $loanId, ?string $encrRef = null, ?string $reference = 'BANKREF1'): int
    {
        $loans = new Loan();
        $loan = $loans->find($loanId);
        return (int) $loans->transaction(fn () => (new LoanReleaseService())->release($loanId, $loan, $this->userId, null, $encrRef ? 'Bank Transfer' : 'Cash', $encrRef ?? $reference, $encrRef));
    }

    private function journals(int $loanId, string $module = 'LOAN_RELEASED', string $status = 'Posted'): array
    {
        $stmt = $this->db->prepare("SELECT * FROM accounting_journal_entries WHERE source_table = 'loans' AND source_id = ? AND source_module = ? AND status = ?");
        $stmt->execute([$loanId, $module, $status]);
        return $stmt->fetchAll();
    }

    private function status(int $loanId): string
    {
        return (string) $this->one('SELECT loan_status FROM loans WHERE id = ?', [$loanId]);
    }

    private function encrRef(int $loanId): string
    {
        return CollexiaEncrDisbursementService::newReference($loanId);
    }

    private function setState(int $disbursementId, string $state, int $maybe = 0): void
    {
        (new Loan())->updateDisbursementFields($disbursementId, ['encr_state' => $state, 'encr_maybe_received' => $maybe]);
    }

    /** The real service with Collexia (and only Collexia) mocked. */
    private function encrService(CollexiaEncrApiClient $api): CollexiaEncrDisbursementService
    {
        $settings = $this->createMock(CollexiaSetting::class);
        $settings->method('encrEnabled')->willReturn(true);
        $settings->method('get')->willReturn('');
        $borrowers = $this->createMock(Borrower::class);
        $borrowers->method('bankDetails')->willReturn(['bank_name' => 'FNB', 'account_number' => '62000000001', 'account_type' => 'Savings']);
        return new CollexiaEncrDisbursementService($settings, $api, new Loan(), $borrowers, new LoanReleaseService());
    }

    // --- 1. manual release unchanged ---

    public function testManualReleasePostsEverythingAtOnceAsBefore(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id);

        $this->assertSame('Active', $this->status($id));
        $d = (new Loan())->disbursementById($dId);
        $this->assertSame('Disbursed', $d['status']);
        $this->assertSame('Cash', $d['disbursement_method']);
        $this->assertNull($d['encr_reference_no']);
        $this->assertSame(date('Y-m-d'), $d['disbursement_date']);
        $j = $this->journals($id);
        $this->assertCount(1, $j);
        $this->assertSame('BANKREF1', $j[0]['reference_no']);
        $this->assertSame(date('Y-m-d'), $j[0]['journal_date']);
        $this->assertCount(1, $this->journals($id, 'INTEREST_ACCRUAL'), 'Upfront interest recognised at release');
        $this->assertSame('Loan released / disbursed.', $this->one("SELECT notes FROM loan_status_history WHERE loan_id = ? AND new_status = 'Active'", [$id]));
        $this->assertSame(1000.0, (float) $this->one('SELECT SUM(debit) FROM accounting_journal_lines WHERE journal_id = ?', [$j[0]['id']]));
    }

    public function testASecondReleaseIsRefused(): void
    {
        $id = $this->approvedLoan();
        $this->release($id);
        $this->expectException(\RuntimeException::class);
        $this->release($id);
    }

    // --- 2. EnCr release posts nothing and counts nowhere ---

    public function testEncrReleaseWaitsAndPostsNothing(): void
    {
        $id = $this->approvedLoan();
        $ref = $this->encrRef($id);
        $dId = $this->release($id, $ref);

        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
        $d = (new Loan())->disbursementById($dId);
        $this->assertSame('Pending', $d['status']);
        $this->assertSame($ref, $d['encr_reference_no']);
        $this->assertSame('Not Sent', $d['encr_state']);
        $this->assertNull($d['disbursed_at']);
        $this->assertSame(0, (int) $this->one("SELECT COUNT(*) FROM accounting_journal_entries WHERE source_table = 'loans' AND source_id = ?", [$id]), 'no journal, no cash book');
        $this->assertSame(0, (int) $this->one('SELECT COUNT(*) FROM interest_accruals WHERE loan_id = ?', [$id]), 'no interest recognised');
        $this->assertNull($this->one('SELECT released_at FROM loans WHERE id = ?', [$id]));

        $ledger = LoanStatementService::ledger($id);
        $this->assertSame([], $ledger['events'], 'no "Amount Disbursed", interest or fee line');
        $this->assertSame(0.0, $ledger['closing_balance']);
        $this->assertStringContainsString('Payout pending with Collexia, ref ' . $ref, $ledger['notice']);
    }

    public function testAWaitingLoanIsIgnoredByArrearsPenaltiesAccrualDebitOrdersCplAndDashboards(): void
    {
        $id = $this->approvedLoan();
        // one more instalment falling due in a few days, for the dashboard's "upcoming dues"
        $this->db->prepare("INSERT INTO loan_schedules (loan_id, installment_no, due_date, principal_due, principal_paid, interest_due, interest_paid, total_due, total_paid, status)
                            VALUES (?, 3, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 0, 0, 0, 0, 10, 0, 'Pending')")->execute([$id]);
        $dId = $this->release($id, $this->encrRef($id));
        $today = date('Y-m-d');
        $lists = fn () => [
            'arrears' => in_array($id, array_map('intval', array_column(ArrearsService::overdueLoans($today), 'loan_id')), true),
            'penalties' => PenaltyAccrualService::chargeableInstallments($today, $id) !== [],
            'debit orders' => in_array($id, array_map('intval', array_column((new DebitOrder())->activeLoansMissingDebitOrder(), 'id')), true),
            'CPL' => in_array($id, array_map('intval', array_column((new CplExporter())->eligibleLoans(date('Y-m-t')), 'id')), true),
            'dashboard upcoming dues' => in_array($id, array_map('intval', array_column(DashboardService::upcomingDue(7), 'loan_id')), true),
            'disbursement reports' => (int) $this->one("SELECT COUNT(*) FROM loan_disbursements WHERE loan_id = ? AND status = 'Disbursed'", [$id]) > 0,
            'portfolio' => (int) $this->one("SELECT COUNT(*) FROM loans WHERE id = ? AND loan_status IN ('Active','Current','Released')", [$id]) > 0,
        ];

        foreach ($lists() as $what => $included) {
            $this->assertFalse($included, $what . ' must ignore a loan that is waiting for its payout');
        }
        $this->assertSame([], InterestAccrualService::accruableInstallments($today, $id), 'interest accrual');

        // control: the very same loan counts everywhere once Collexia confirms the payout
        $this->setState($dId, 'Completed');
        $this->assertTrue((new LoanReleaseService())->finaliseEncrPayout($dId, $this->userId)['finalised']);
        foreach ($lists() as $what => $included) {
            $this->assertTrue($included, $what . ' counts the loan once it is paid out');
        }
    }

    // --- 3. Completed finalises, once ---

    public function testCompletedStatusReleasesAndPostsExactlyOnceEvenWhenCheckedTwice(): void
    {
        $id = $this->approvedLoan();
        $ref = $this->encrRef($id);
        $dId = $this->release($id, $ref);
        $this->setState($dId, 'Submitted');
        $paidOn = date('Ymd');

        $api = $this->createMock(CollexiaEncrApiClient::class);
        $api->expects($this->exactly(2))->method('transferInfo')
            ->willReturn(['status' => 3, 'amountPaidOut' => 1000.00, 'responseCode' => '0', 'responseDescription' => 'Transaction Successful', 'statusDate' => $paidOn]);
        $service = $this->encrService($api);

        $first = $service->checkStatus($dId, 'tester', $this->userId);
        $second = $service->checkStatus($dId, 'tester', $this->userId);

        $this->assertTrue($first['ok']);
        $this->assertTrue($first['finalised']);
        $this->assertStringContainsString('released and posted', $first['message']);
        $this->assertFalse($second['finalised'] ?? false);

        $this->assertSame('Active', $this->status($id));
        $j = $this->journals($id);
        $this->assertCount(1, $j, 'posted once');
        $this->assertSame($ref, $j[0]['reference_no'], 'the Cash Book / journal carry the payout reference');
        $this->assertCount(1, $this->journals($id, 'INTEREST_ACCRUAL'));
        $d = (new Loan())->disbursementById($dId);
        $this->assertSame('Disbursed', $d['status']);
        $this->assertSame('Completed', $d['encr_state']);
        $this->assertSame(date('Y-m-d'), $d['disbursement_date']);
        $this->assertSame(1, (int) $this->one("SELECT COUNT(*) FROM loan_status_history WHERE loan_id = ? AND old_status = 'Awaiting Payout' AND new_status = 'Active'", [$id]));

        $ledger = LoanStatementService::ledger($id);
        $this->assertSame('Amount Disbursed - Ref ' . $ref, $ledger['events'][0]['description']);
    }

    public function testFinalisingTwiceDirectlyPostsOnce(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));
        $this->setState($dId, 'Completed');

        $svc = new LoanReleaseService();
        $this->assertTrue($svc->finaliseEncrPayout($dId, $this->userId)['finalised']);
        $again = $svc->finaliseEncrPayout($dId, $this->userId);
        $this->assertFalse($again['finalised']);
        $this->assertFalse($again['error']);
        $this->assertCount(1, $this->journals($id));
    }

    public function testNothingIsPostedBeforeCollexiaSaysCompleted(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));
        $this->setState($dId, 'Submitted');

        $this->assertFalse((new LoanReleaseService())->finaliseEncrPayout($dId, $this->userId)['finalised']);
        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
        $this->assertCount(0, $this->journals($id));
    }

    // --- 4. Failed / not sent leaves nothing ---

    public function testAFailedPayoutLeavesTheLoanWaitingWithNothingPosted(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));
        $this->setState($dId, 'Submitted');

        $api = $this->createMock(CollexiaEncrApiClient::class);
        $api->method('transferInfo')->willReturn(['status' => 7, 'amountPaidOut' => 0, 'responseCode' => '48', 'responseDescription' => 'Account no fails CDV routine']);
        $r = $this->encrService($api)->checkStatus($dId, 'tester', $this->userId);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Nothing was posted', $r['message']);
        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
        $this->assertSame('Failed', (new Loan())->disbursementById($dId)['encr_state']);
        $this->assertSame(0, (int) $this->one("SELECT COUNT(*) FROM accounting_journal_entries WHERE source_table = 'loans' AND source_id = ?", [$id]));
    }

    public function testARefusedSendLeavesTheLoanWaitingWithNothingPosted(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));

        $api = $this->createMock(CollexiaEncrApiClient::class);
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 1001, 'code' => '1001', 'message' => 'Account Invalid']], 400, 'Transaction Failed'));
        $r = $this->encrService($api)->send($dId, 'tester');

        $this->assertFalse($r['ok']);
        $d = (new Loan())->disbursementById($dId);
        $this->assertSame('Not Sent', $d['encr_state']);
        $this->assertSame(0, (int) $d['encr_maybe_received']);
        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
        $this->assertCount(0, $this->journals($id));
        $this->assertNull(LoanReleaseService::whyPayoutCannotBeAbandoned($d), 'a clear refusal may be paid another way');
    }

    public function testAServerErrorOnSendMeansTheMoneyMightStillLeave(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));

        $api = $this->createMock(CollexiaEncrApiClient::class);
        $api->method('transfer')->willThrowException(new CollexiaApiException([['rcoId' => 192, 'code' => '192', 'message' => 'Internal server error']], 500, 'Error'));
        $this->encrService($api)->send($dId, 'tester');

        $this->assertSame(1, (int) (new Loan())->disbursementById($dId)['encr_maybe_received']);
        $svc = new LoanReleaseService();
        $this->assertFalse($svc->payEncrLoanAnotherWay($id, $this->userId, 'Cash', null, 'X')['ok']);
        $this->assertFalse($svc->cancelEncrRelease($id, $this->userId)['ok']);
        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
    }

    // --- 5. pay another way / cancel release ---

    public function testPayAnotherWayAndCancelAreRefusedWhileSubmitted(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));
        $this->setState($dId, 'Submitted');
        $svc = new LoanReleaseService();

        $pay = $svc->payEncrLoanAnotherWay($id, $this->userId, 'Cash', null, 'X');
        $cancel = $svc->cancelEncrRelease($id, $this->userId);

        $this->assertFalse($pay['ok']);
        $this->assertStringContainsString('may still leave', $pay['message']);
        $this->assertFalse($cancel['ok']);
        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
        $this->assertSame('Pending', (new Loan())->disbursementById($dId)['status']);
        $this->assertCount(0, $this->journals($id));
    }

    public function testPayAnotherWayReleasesTheLoanTheUsualWay(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));
        $this->setState($dId, 'Failed');

        $r = (new LoanReleaseService())->payEncrLoanAnotherWay($id, $this->userId, 'Bank Transfer', null, 'EFT-777');

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('Active', $this->status($id));
        $j = $this->journals($id);
        $this->assertCount(1, $j);
        $this->assertSame('EFT-777', $j[0]['reference_no']);
        $loans = new Loan();
        $this->assertSame('Cancelled', $loans->disbursementById($dId)['status'], 'the EnCr payout is set aside');
        $active = $loans->activeDisbursement($id);
        $this->assertSame('Disbursed', $active['status']);
        $this->assertSame('Bank Transfer', $active['disbursement_method']);
        $this->assertNull($active['encr_reference_no']);

        // the set-aside payout can never be sent afterwards
        $api = $this->createMock(CollexiaEncrApiClient::class);
        $api->expects($this->never())->method('transfer');
        $this->assertFalse($this->encrService($api)->send($dId, 'tester')['ok']);
    }

    public function testCancelReleaseGoesBackToApprovedAndTheLoanCanBeReleasedAgain(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));

        $r = (new LoanReleaseService())->cancelEncrRelease($id, $this->userId);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('Approved', $this->status($id));
        $this->assertSame('Cancelled', (new Loan())->disbursementById($dId)['status']);
        $this->assertCount(0, $this->journals($id));

        $this->release($id);
        $this->assertSame('Active', $this->status($id));
        $this->assertCount(1, $this->journals($id));
    }

    // --- 6. one-off clean-up of a loan posted at release before this change ---

    public function testUnpostScriptReversesAnOldEncrReleaseAndCompletedPostsItAgainOnce(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id);   // the old behaviour: posted at release
        $ref = $this->encrRef($id);
        (new Loan())->updateDisbursementFields($dId, ['encr_reference_no' => $ref, 'encr_state' => 'Not Sent', 'reference_no' => $ref]);

        $svc = new EncrUnpostService();
        $candidate = null;
        foreach ($svc->candidates() as $c) {
            if ((int) $c['loan_id'] === $id) {
                $candidate = $c;
            }
        }
        $this->assertNotNull($candidate);
        $this->assertSame([], $svc->plan($candidate)['refusals']);

        $r = $svc->unpost($candidate);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
        $this->assertCount(0, $this->journals($id), 'nothing left posted');
        $this->assertCount(1, $this->journals($id, 'LOAN_RELEASED', 'Reversed'));
        $this->assertCount(1, $this->journals($id, 'INTEREST_ACCRUAL', 'Reversed'));
        $this->assertSame(0, (int) $this->one('SELECT COUNT(*) FROM interest_accruals WHERE loan_id = ?', [$id]));
        $this->assertSame('Pending', (new Loan())->disbursementById($dId)['status']);
        $this->assertSame(0.0, (float) $this->one(
            "SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM accounting_journal_lines l JOIN accounting_journal_entries e ON e.id = l.journal_id
             WHERE l.account_id = (SELECT id FROM accounting_accounts WHERE account_code = '1020')
               AND (e.source_table = 'loans' AND e.source_id = ? OR e.source_table = 'accounting_journal_entries' AND e.source_id IN
                    (SELECT id FROM (SELECT id FROM accounting_journal_entries WHERE source_table = 'loans' AND source_id = ?) x))", [$id, $id]),
            'loans receivable back to zero');

        $this->setState($dId, 'Completed');
        $this->assertTrue((new LoanReleaseService())->finaliseEncrPayout($dId, $this->userId)['finalised']);
        $this->assertCount(1, $this->journals($id));
        $this->assertCount(1, $this->journals($id, 'INTEREST_ACCRUAL'), 'interest recognised again at the confirmed payout');
        $this->assertSame('Active', $this->status($id));
    }

    public function testUnpostScriptRefusesALoanWithAPayment(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id);
        (new Loan())->updateDisbursementFields($dId, ['encr_reference_no' => $this->encrRef($id), 'encr_state' => 'Not Sent']);
        $this->db->prepare("UPDATE loan_schedules SET total_paid = 100, status = 'Partial' WHERE loan_id = ? AND installment_no = 1")->execute([$id]);

        $svc = new EncrUnpostService();
        $candidate = array_values(array_filter($svc->candidates(), fn ($c) => (int) $c['loan_id'] === $id))[0];
        $r = $svc->unpost($candidate);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('payment', $r['message']);
        $this->assertSame('Active', $this->status($id));
        $this->assertCount(1, $this->journals($id));
    }

    // --- review fixes ---

    public function testWhileAPayoutIsBeingSentItCanNeitherBePaidAnotherWayNorCancelled(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));
        $svc = new LoanReleaseService();
        $during = [];

        // A second member of staff clicks "Pay out another way" and "Cancel release" while the Collexia call is in flight.
        $api = $this->createMock(CollexiaEncrApiClient::class);
        $api->expects($this->once())->method('transfer')->willReturnCallback(function () use ($svc, $id, &$during) {
            $during['pay'] = $svc->payEncrLoanAnotherWay($id, $this->userId, 'Cash', null, 'X');
            $during['cancel'] = $svc->cancelEncrRelease($id, $this->userId);
            return [];
        });
        $r = $this->encrService($api)->send($dId, 'tester');

        $this->assertFalse($during['pay']['ok']);
        $this->assertStringContainsString('may still leave', $during['pay']['message']);
        $this->assertFalse($during['cancel']['ok']);
        $this->assertTrue($r['ok'], $r['message']);
        $d = (new Loan())->disbursementById($dId);
        $this->assertSame('Pending', $d['status']);
        $this->assertSame('Submitted', $d['encr_state']);
        $this->assertSame(0, (int) $d['encr_maybe_received'], 'a clear answer clears the in-flight mark');
        $this->assertSame(Loan::STATUS_AWAITING_PAYOUT, $this->status($id));
        $this->assertCount(0, $this->journals($id));
    }

    public function testAnAcceptanceLandingOnAPayoutSetAsideMeanwhileIsWarnedAboutNotHidden(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id, $this->encrRef($id));

        // The claim makes this impossible through the app; simulate the row being set aside behind its back.
        $api = $this->createMock(CollexiaEncrApiClient::class);
        $api->method('transfer')->willReturnCallback(function () use ($dId) {
            $this->db->prepare("UPDATE loan_disbursements SET status = 'Cancelled' WHERE id = ?")->execute([$dId]);
            return [];
        });
        $r = $this->encrService($api)->send($dId, 'tester');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('paid twice', $r['message']);
        $d = (new Loan())->disbursementById($dId);
        $this->assertSame('Cancelled', $d['status']);
        $this->assertStringContainsString('WARNING', $d['encr_last_message']);
    }

    public function testUnpostTakesBackPeriodicallyAccruedInterestSoItIsNotCountedTwice(): void
    {
        $today = date('Y-m-d');
        $id = $this->approvedLoan('Progressive', [$today, date('Y-m-d', strtotime('+1 month'))]);
        $dId = $this->release($id);   // the old behaviour: posted at release
        (new Loan())->updateDisbursementFields($dId, ['encr_reference_no' => $this->encrRef($id), 'encr_state' => 'Not Sent']);
        $this->accrueFor($id);        // the nightly accrual reaches the first instalment (a batch journal, no loan id)
        $this->assertSame(150.0, $this->interestReceivable($id));

        $svc = new EncrUnpostService();
        $candidate = array_values(array_filter($svc->candidates(), fn ($c) => (int) $c['loan_id'] === $id))[0];
        $plan = $svc->plan($candidate);
        $this->assertSame([], $plan['refusals']);
        $this->assertSame(150.0, $plan['periodic_interest']);

        $r = $svc->unpost($candidate);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(0.0, $this->interestReceivable($id), 'the accrued interest is taken back');
        $this->assertCount(1, $this->journals($id, 'INTEREST_ACCRUAL_REVERSAL'));

        // Collexia completes it later: the instalment is accrued again exactly once.
        $this->setState($dId, 'Completed');
        $this->assertTrue((new LoanReleaseService())->finaliseEncrPayout($dId, $this->userId)['finalised']);
        $this->accrueFor($id);
        $this->accrueFor($id);
        $this->assertSame(150.0, $this->interestReceivable($id));
        $this->assertSame(1, (int) $this->one('SELECT COUNT(*) FROM interest_accruals WHERE loan_id = ?', [$id]));
    }

    public function testAnUnpostedNotSentPayoutMustBeCheckedBeforeItCanBePaidAnotherWay(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id);
        // what the old code stored for the sandbox loan: a plain "Not Sent" after "192 Internal server error"
        (new Loan())->updateDisbursementFields($dId, ['encr_reference_no' => $this->encrRef($id), 'encr_state' => 'Not Sent', 'encr_last_message' => 'Collexia refused the payout: 192 Internal server error']);

        $svc = new EncrUnpostService();
        $candidate = array_values(array_filter($svc->candidates(), fn ($c) => (int) $c['loan_id'] === $id))[0];
        $this->assertSame(0.0, $svc->plan($candidate)['periodic_interest'], 'Upfront interest is reversed with the loan\'s own journal');
        $this->assertTrue($svc->unpost($candidate)['ok']);

        $d = (new Loan())->disbursementById($dId);
        $this->assertSame(1, (int) $d['encr_maybe_received']);
        $this->assertFalse((new LoanReleaseService())->payEncrLoanAnotherWay($id, $this->userId, 'Cash', null, 'X')['ok']);
        $this->assertFalse((new LoanReleaseService())->cancelEncrRelease($id, $this->userId)['ok']);
    }

    public function testUnpostRefusesALoanWhoseLevyWasAlreadySubmitted(): void
    {
        $id = $this->approvedLoan();
        $dId = $this->release($id);
        (new Loan())->updateDisbursementFields($dId, ['encr_reference_no' => $this->encrRef($id), 'encr_state' => 'Not Sent']);
        $this->db->prepare("INSERT INTO namfisa_levy_transactions (loan_id, borrower_id, branch_id, levy_date, levy_rate, basis_amount, levy_amount, status)
                            VALUES (?, ?, ?, CURDATE(), 1.03, 1000, 10.30, 'Submitted')")->execute([$id, $this->borrowerId, $this->branchId]);

        $svc = new EncrUnpostService();
        $candidate = array_values(array_filter($svc->candidates(), fn ($c) => (int) $c['loan_id'] === $id))[0];
        $r = $svc->unpost($candidate);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('NAMFISA submission', $r['message']);
        $this->assertSame('Active', $this->status($id));
        $this->assertSame('Submitted', $this->one('SELECT status FROM namfisa_levy_transactions WHERE loan_id = ?', [$id]));
    }
}
