<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;
use App\Services\CollexiaCollectionStopper;
use App\Services\MandateCollectionCheck;
use App\Support\SplitLanePlanner;
use PHPUnit\Framework\TestCase;

/**
 * A manual payment made while a Collexia mandate is live: the check says what is still going to be collected, and the stopper cancels
 * exactly the collections that are no longer needed -- never one due today or tomorrow, never one still owed. Collexia is replaced by
 * a fake, so nothing leaves this machine; local dev database, throwaway rows removed afterwards.
 */
class CollectionStopTest extends TestCase
{
    private \PDO $db;
    private array $template = [];
    private ?int $loanId = null;
    private ?int $debitOrderId = null;
    private array $scheduleIds = [];
    private array $laneRowIds = [];
    private ?int $legId = null;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->template = $this->db->query('SELECT borrower_id, product_id, plan_id, branch_id FROM loans ORDER BY id LIMIT 1')->fetch() ?: [];
        if (!$this->template) {
            $this->markTestSkipped('No loan in the local database to borrow foreign keys from.');
        }
        if (!$this->db->query("SHOW COLUMNS FROM debit_order_split_leg_installments LIKE 'cancelled_at'")->fetch()) {
            $this->markTestSkipped('database/debit_order_lane_auto_cancel.sql has not been applied to this database.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->debitOrderId) {
            $this->db->prepare('DELETE FROM debit_order_split_leg_installments WHERE leg_id IN (SELECT id FROM debit_order_split_legs WHERE debit_order_id = ?)')->execute([$this->debitOrderId]);
            $this->db->prepare('DELETE FROM debit_order_split_legs WHERE debit_order_id = ?')->execute([$this->debitOrderId]);
            $this->db->prepare('DELETE FROM debit_orders WHERE id = ?')->execute([$this->debitOrderId]);
        }
        if ($this->loanId) {
            $this->db->prepare('DELETE FROM loan_schedules WHERE loan_id = ?')->execute([$this->loanId]);
            $this->db->prepare('DELETE FROM loans WHERE id = ?')->execute([$this->loanId]);
        }
    }

    /**
     * @param array<int, array{0: string, 1: float, 2: float}> $rows due date, amount due, amount paid (full = Paid)
     */
    private function makeLoan(array $rows, bool $split, float $debitAmount = 100.0, string $startDate = '2027-01-17'): void
    {
        $this->db->prepare(
            "INSERT INTO loans (loan_no, borrower_id, product_id, plan_id, branch_id, loan_status, principal_amount, installment_amount, payment_day, start_date)
             VALUES (?, ?, ?, ?, ?, 'Active', 1000, 100, 17, '2027-01-17')"
        )->execute(['LN-STOP-' . uniqid(), $this->template['borrower_id'], $this->template['product_id'], $this->template['plan_id'], $this->template['branch_id']]);
        $this->loanId = (int) $this->db->lastInsertId();

        foreach ($rows as $i => [$due, $amount, $paid]) {
            $this->db->prepare(
                "INSERT INTO loan_schedules (loan_id, installment_no, due_date, total_due, total_paid, status, namfisa_levy_due, duty_stamp_due, namfisa_levy_paid, duty_stamp_paid)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0)"
            )->execute([$this->loanId, $i + 1, $due, $amount, $paid, $paid >= $amount ? 'Paid' : 'Pending']);
            $this->scheduleIds[$i + 1] = (int) $this->db->lastInsertId();
        }

        $this->db->prepare(
            "INSERT INTO debit_orders (borrower_id, loan_id, debit_order_no, bank_name, account_number, debit_day, debit_amount, start_date, status, id_type, account_type, bank_code, no_of_days_tracking, split_enabled, collexia_api_status, collexia_api_contract_reference)
             VALUES (?, ?, ?, 'FNB', '123', 17, ?, ?, 'Active', 1, 1, 'FN', 3, ?, 'Registered', ?)"
        )->execute([$this->template['borrower_id'], $this->loanId, 'DO-STOP-' . uniqid(), $debitAmount, $startDate, $split ? 1 : 0, $split ? null : 'CTRSINGLE00001']);
        $this->debitOrderId = (int) $this->db->lastInsertId();

        if ($split) {
            $plan = [];
            foreach ($rows as $i => [$due, $amount]) {
                $plan[] = ['schedule_id' => $this->scheduleIds[$i + 1], 'loan_installment_no' => $i + 1, 'date' => $due, 'amounts' => [$amount]];
            }
            $lane = SplitLanePlanner::planSingle($plan)[0];
            $this->legId = (new DebitOrderSplitLeg())->upsert($this->debitOrderId, 1, $lane['amount'], 1, null, $lane['first_date']);
            $laneModel = new DebitOrderSplitLegInstallment();
            foreach ($lane['installments'] as $item) {
                $this->laneRowIds[$item['installment_no']] = $laneModel->add($this->legId, $item['installment_no'], $item['schedule_id'], $item['date'], $item['amount']);
                $laneModel->markApplied($this->laneRowIds[$item['installment_no']]);
            }
            $this->db->prepare("UPDATE debit_order_split_legs SET collexia_api_status = 'Registered', collexia_api_contract_reference = 'CTRLANE0000001' WHERE id = ?")->execute([$this->legId]);
        }
    }

    private function fake(array $installments, ?\Throwable $cancelThrows = null): object
    {
        return new class ($installments, $cancelThrows) {
            public array $cancelled = [];
            public function __construct(private array $installments, private ?\Throwable $cancelThrows) {}
            public function installmentRequest(string $c): array { return ['installments' => $this->installments]; }
            public function cancelInstallment(string $c, int $no, string $u): array
            {
                if ($this->cancelThrows) {
                    throw $this->cancelThrows;
                }
                $this->cancelled[] = [$c, $no];
                return [];
            }
            public function mandateEnquiry(array $f): array { return []; }
        };
    }

    /** Collexia's own view: one entry per [date, status]. */
    private function holds(array $dates, int $status = 1): array
    {
        $out = [];
        foreach (array_values($dates) as $i => $d) {
            $out[] = ['installmentNo' => $i + 1, 'scheduledDate' => str_replace('-', '', $d), 'installmentAmount' => 100.0, 'status' => is_array($status) ? $status[$i] : $status, 'intId' => 7000 + $i];
        }
        return $out;
    }

    private function stopper(object $client): CollexiaCollectionStopper
    {
        return new CollexiaCollectionStopper(fn () => $client);
    }

    private function debitOrder(): array
    {
        return (new \App\Models\DebitOrder())->find($this->debitOrderId);
    }

    // --- boundaries and dates (pure) ---

    public function testACollectionDueTodayOrTomorrowCanNoLongerBeStopped(): void
    {
        $this->assertTrue(MandateCollectionCheck::isImminent('2027-03-16', '2027-03-16'), 'today');
        $this->assertTrue(MandateCollectionCheck::isImminent('2027-03-17', '2027-03-16'), 'tomorrow');
        $this->assertFalse(MandateCollectionCheck::isImminent('2027-03-18', '2027-03-16'), 'the day after is still stoppable');
    }

    public function testRecurringDatesFollowTheDebitDayAndCapAtMonthEnd(): void
    {
        $dates = MandateCollectionCheck::recurringDates('2027-01-31', 31, '2027-04-30');
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], $dates);
        $this->assertSame(['2027-01-17', '2027-02-17', '2027-03-17'], MandateCollectionCheck::recurringDates('2027-01-17', 17, '2027-03-20'));
    }

    // --- lane mandate ---

    public function testALaneCollectionForAnAlreadyPaidInstallmentIsFlaggedAndStoppableExactlyForThatInstallment(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0], ['2027-03-17', 100, 0]], true);

        $check = (new MandateCollectionCheck())->forLoan((int) $this->loanId, '2026-12-01');
        $this->assertSame('lane', $check['kind']);
        $this->assertCount(1, $check['paidButScheduled']);
        $this->assertSame(1, $check['paidButScheduled'][0]['installment_no']);
        $this->assertFalse($check['paidButScheduled'][0]['imminent']);

        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17', '2027-03-17']));
        $result = $this->stopper($client)->stopInstallment($this->debitOrder(), $this->scheduleIds[1], 'willemk', '2026-12-01');

        $this->assertTrue($result['ok']);
        $this->assertSame([['CTRLANE0000001', 1]], $client->cancelled, 'exactly that installment, no other');
        $row = $this->db->query('SELECT cancelled_at FROM debit_order_split_leg_installments WHERE id = ' . $this->laneRowIds[1])->fetchColumn();
        $this->assertNotEmpty($row);

        // Once cancelled it no longer shows as scheduled -- the notice goes away.
        $after = (new MandateCollectionCheck())->forLoan((int) $this->loanId, '2026-12-01');
        $this->assertSame([], $after['paidButScheduled']);
    }

    public function testAnInstallmentThatIsStillOwedIsNeverStopped(): void
    {
        $this->makeLoan([['2027-01-17', 100, 40], ['2027-02-17', 100, 0]], true);
        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17']));

        $result = $this->stopper($client)->stopInstallment($this->debitOrder(), $this->scheduleIds[1], 'willemk', '2026-12-01');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $client->cancelled);
    }

    public function testACollectionDueTomorrowIsWarnedAboutButNotCancelled(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0]], true);

        $check = (new MandateCollectionCheck())->forLoan((int) $this->loanId, '2027-01-16');   // tomorrow IS the 17th
        $this->assertTrue($check['paidButScheduled'][0]['imminent']);

        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17']));
        $result = $this->stopper($client)->stopInstallment($this->debitOrder(), $this->scheduleIds[1], 'willemk', '2027-01-16');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $client->cancelled, 'too late -- it is on its way to the bank');
        $this->assertStringContainsString('can no longer be stopped', implode(' ', $result['messages']));
    }

    public function testNothingIsCancelledWhileTheMandateIsStillBeingConfirmed(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0]], true);
        $this->db->prepare('UPDATE debit_order_split_leg_installments SET applied_at = NULL WHERE id = ?')->execute([$this->laneRowIds[2]]);
        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17']));

        $result = $this->stopper($client)->stopInstallment($this->debitOrder(), $this->scheduleIds[1], 'willemk', '2026-12-01');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $client->cancelled);
    }

    public function testACollectionCollexiaAlreadySentIsNotCancelled(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0]], true);
        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17'], 4));   // Send For Payment

        $result = $this->stopper($client)->stopInstallment($this->debitOrder(), $this->scheduleIds[1], 'willemk', '2026-12-01');

        $this->assertSame([], $client->cancelled);
        $this->assertFalse($result['ok']);
    }

    // --- single recurring mandate ---

    public function testASingleMandateThatWillOverCollectReportsTheSurplusFromTheEnd(): void
    {
        // 4 monthly instalments of 100; the borrower paid the first two by hand.
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 100], ['2027-03-17', 100, 0], ['2027-04-17', 100, 0]], false);

        $check = (new MandateCollectionCheck())->forLoan((int) $this->loanId, '2026-12-01');

        $this->assertSame('single', $check['kind']);
        $this->assertSame(2, $check['surplus']['count']);
        $this->assertSame(['2027-03-17', '2027-04-17'], $check['surplus']['dates'], 'the LAST collections are the surplus');
        $this->assertSame(200.0, $check['surplus']['amount']);
    }

    public function testASingleMandateThatMatchesTheLoanHasNoSurplus(): void
    {
        $this->makeLoan([['2027-01-17', 100, 0], ['2027-02-17', 100, 0]], false);
        $check = (new MandateCollectionCheck())->forLoan((int) $this->loanId, '2026-12-01');
        $this->assertNull($check['surplus']);
    }

    public function testStoppingTheSurplusCancelsOnlyTheLastCollectionsCollexiaStillHolds(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 100], ['2027-03-17', 100, 0], ['2027-04-17', 100, 0]], false);
        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17', '2027-03-17', '2027-04-17']));

        $result = $this->stopper($client)->stopSurplus($this->debitOrder(), 'willemk', '2026-12-01');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['cancelled']);
        $cancelledNumbers = array_column($client->cancelled, 1);
        sort($cancelledNumbers);
        $this->assertSame([3, 4], $cancelledNumbers, 'installments 1 and 2 are kept');
    }

    public function testThePartOfTheSurplusDueTodayOrTomorrowIsLeftAlone(): void
    {
        // The loan is fully paid; 2 collections remain, one of them tomorrow.
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 100], ['2027-03-17', 100, 100], ['2027-04-17', 100, 100]], false);
        $check = (new MandateCollectionCheck())->forLoan((int) $this->loanId, '2027-03-16');
        $this->assertSame(2, $check['surplus']['count']);
        $this->assertSame(1, $check['surplus']['stoppable']);

        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17', '2027-03-17', '2027-04-17']));
        $result = $this->stopper($client)->stopSurplus($this->debitOrder(), 'willemk', '2027-03-16');

        $this->assertSame([['CTRSINGLE00001', 4]], $client->cancelled, 'only the 17 April collection can still be stopped');
        $this->assertStringContainsString('Only 1 of the 2', implode(' ', $result['messages']));
    }

    public function testStoppingTheSurplusChangesNothingWhenTheMandateMatchesWhatIsOwed(): void
    {
        $this->makeLoan([['2027-01-17', 100, 0], ['2027-02-17', 100, 0]], false);
        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17']));

        $result = $this->stopper($client)->stopSurplus($this->debitOrder(), 'willemk', '2026-12-01');

        $this->assertSame([], $client->cancelled);
        $this->assertTrue($result['ok']);
    }

    public function testARefusedCancelIsReportedAndCountsAsNothingStopped(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0]], true);
        $client = $this->fake($this->holds(['2027-01-17', '2027-02-17']), new \RuntimeException('Collexia is unavailable'));

        $result = $this->stopper($client)->stopInstallment($this->debitOrder(), $this->scheduleIds[1], 'willemk', '2026-12-01');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['cancelled']);
        $this->assertNull($this->db->query('SELECT cancelled_at FROM debit_order_split_leg_installments WHERE id = ' . $this->laneRowIds[1])->fetchColumn() ?: null);
    }
}
