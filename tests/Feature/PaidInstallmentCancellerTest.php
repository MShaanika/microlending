<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;
use App\Services\CollexiaApiException;
use App\Services\CollexiaMandateFinalizer;
use App\Services\CollexiaPaidInstallmentCanceller;
use App\Support\SplitLanePlanner;
use PHPUnit\Framework\TestCase;

/**
 * Installments already paid by hand are sent in the mandate and then cancelled automatically. Every guard
 * is exercised against a fake Collexia client, so nothing leaves this machine. Runs against the local dev
 * database like the other Feature tests, with its own throwaway rows removed afterwards.
 */
class PaidInstallmentCancellerTest extends TestCase
{
    private \PDO $db;
    private ?int $loanId = null;
    private ?int $debitOrderId = null;
    /** @var int[] loan_schedules ids, in loan-installment order */
    private array $scheduleIds = [];
    private array $leg = [];
    /** @var int[] lane row ids by Collexia installment number */
    private array $laneRowIds = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        if (!$this->db->query("SHOW COLUMNS FROM debit_order_split_leg_installments LIKE 'auto_cancel'")->fetch()) {
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
     * A registered mandate of three installments: #1 already paid by hand and flagged to cancel, #2 and #3 owing.
     *
     * @param array{paid_in_full?: bool, flag_all?: bool, applied?: bool} $opt
     */
    private function build(array $opt = []): void
    {
        $template = $this->db->query('SELECT borrower_id, product_id, plan_id, branch_id FROM loans ORDER BY id LIMIT 1')->fetch();
        if (!$template) {
            $this->markTestSkipped('No loan in the local database to borrow foreign keys from.');
        }
        $this->db->prepare(
            "INSERT INTO loans (loan_no, borrower_id, product_id, plan_id, branch_id, loan_status, principal_amount, installment_amount, payment_day, start_date)
             VALUES (?, ?, ?, ?, ?, 'Active', 100, 27.57, 17, '2026-10-16')"
        )->execute(['LN-TEST-' . uniqid(), $template['borrower_id'], $template['product_id'], $template['plan_id'], $template['branch_id']]);
        $this->loanId = (int) $this->db->lastInsertId();

        $paid = $opt['paid_in_full'] ?? true;
        $rows = [['2027-11-17', 27.57, $paid ? 27.57 : 10.00, $paid ? 'Paid' : 'Partial'], ['2027-12-17', 27.57, 0, 'Pending'], ['2028-01-17', 27.56, 0, 'Pending']];
        foreach ($rows as $i => [$due, $amount, $paidAmount, $status]) {
            $this->db->prepare(
                "INSERT INTO loan_schedules (loan_id, installment_no, due_date, total_due, total_paid, status, namfisa_levy_due, duty_stamp_due, namfisa_levy_paid, duty_stamp_paid)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0)"
            )->execute([$this->loanId, $i + 1, $due, $amount, $paidAmount, $status]);
            $this->scheduleIds[] = (int) $this->db->lastInsertId();
        }

        $this->db->prepare(
            "INSERT INTO debit_orders (borrower_id, loan_id, debit_order_no, bank_name, account_number, debit_day, debit_amount, start_date, status, id_type, account_type, bank_code, no_of_days_tracking, split_enabled)
             VALUES (?, ?, ?, 'FNB', '123', 17, 27.57, '2027-11-17', 'Active', 1, 1, 'FN', 3, 1)"
        )->execute([$template['borrower_id'], $this->loanId, 'DO-TEST-' . uniqid()]);
        $this->debitOrderId = (int) $this->db->lastInsertId();

        $lanes = SplitLanePlanner::planSingle([
            ['schedule_id' => $this->scheduleIds[0], 'loan_installment_no' => 1, 'date' => '2027-11-17', 'amounts' => [27.57], 'auto_cancel' => true],
            ['schedule_id' => $this->scheduleIds[1], 'loan_installment_no' => 2, 'date' => '2027-12-17', 'amounts' => [27.57], 'auto_cancel' => !empty($opt['flag_all'])],
            ['schedule_id' => $this->scheduleIds[2], 'loan_installment_no' => 3, 'date' => '2028-01-17', 'amounts' => [27.56], 'auto_cancel' => !empty($opt['flag_all'])],
        ]);
        $legs = new DebitOrderSplitLeg();
        $lane = new DebitOrderSplitLegInstallment();
        $legId = $legs->upsert($this->debitOrderId, 1, $lanes[0]['amount'], 1, null, $lanes[0]['first_date']);
        foreach ($lanes[0]['installments'] as $item) {
            $this->laneRowIds[$item['installment_no']] = $lane->add($legId, $item['installment_no'], $item['schedule_id'], $item['date'], $item['amount'], !empty($item['auto_cancel']));
        }
        if ($opt['applied'] ?? true) {
            foreach ($this->laneRowIds as $id) {
                $lane->markApplied($id);
            }
        }
        $this->leg = ['id' => $legId, 'split_no' => 1, 'collexia_api_status' => 'Registered', 'collexia_api_contract_reference' => 'CTR000000001'];
    }

    /** A fake Collexia: what installmentRequest returns, and a record of every cancel it was asked for. */
    private function fakeClient(array $installments, ?\Throwable $cancelThrows = null): object
    {
        return new class ($installments, $cancelThrows) {
            public array $cancelled = [];
            public function __construct(private array $installments, private ?\Throwable $cancelThrows) {}
            public array $updated = [];
            public function requestFinalFate(string $contract, string $user): array { return ['mandateLoaded' => true]; }
            public function updateInstallment(array $installments, string $user): array { $this->updated[] = $installments; return []; }
            public function installmentRequest(string $contract): array { return ['installments' => $this->installments]; }
            public function cancelInstallment(string $contract, int $no, string $user): array
            {
                if ($this->cancelThrows) {
                    throw $this->cancelThrows;
                }
                $this->cancelled[] = [$contract, $no, $user];
                return [];
            }
        };
    }

    private function collexiaHolds(array $statusByNo = []): array
    {
        $dates = [1 => '20271117', 2 => '20271217', 3 => '20280117'];
        $out = [];
        foreach ($dates as $no => $date) {
            $out[] = ['installmentNo' => $no, 'scheduledDate' => $date, 'installmentAmount' => 27.57, 'status' => $statusByNo[$no] ?? 1, 'intId' => 9000 + $no];
        }
        return $out;
    }

    private function cancelWith(object $client, ?string $today = '2027-10-01'): array
    {
        return (new CollexiaPaidInstallmentCanceller(fn () => $client))->cancelForLeg($this->leg, 'willemk', $today);
    }

    private function laneRow(int $no): array
    {
        $stmt = $this->db->prepare('SELECT * FROM debit_order_split_leg_installments WHERE id = ?');
        $stmt->execute([$this->laneRowIds[$no]]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    // --- the planner only carries the flag ---

    public function testThePlannerCarriesTheFlagThroughEveryPath(): void
    {
        $rows = [
            ['schedule_id' => 1, 'loan_installment_no' => 1, 'date' => '2027-11-17', 'amounts' => [10.0], 'auto_cancel' => true],
            ['schedule_id' => 2, 'loan_installment_no' => 2, 'date' => '2027-12-17', 'amounts' => [10.0]],
        ];
        $single = SplitLanePlanner::planSingle($rows)[0]['installments'];
        $this->assertTrue($single[0]['auto_cancel']);
        $this->assertFalse($single[1]['auto_cancel']);

        $lanes = SplitLanePlanner::plan($rows);
        $this->assertTrue($lanes[0]['installments'][0]['auto_cancel']);
        $this->assertFalse($lanes[0]['installments'][1]['auto_cancel']);

        // The automatic fallback rebuilds the plan from stored lane rows -- the flag must survive that round trip.
        $stored = [
            ['schedule_id' => 1, 'loan_installment_no' => 1, 'planned_date' => '2027-11-17', 'planned_amount' => 10, 'auto_cancel' => 1],
            ['schedule_id' => 2, 'loan_installment_no' => 2, 'planned_date' => '2027-12-17', 'planned_amount' => 10, 'auto_cancel' => 0],
        ];
        $back = SplitLanePlanner::rowsFromLaneRows($stored);
        $this->assertTrue($back[0]['auto_cancel']);
        $this->assertFalse($back[1]['auto_cancel']);
    }

    // --- cancelling ---

    public function testCancelsOnlyTheInstallmentFlaggedAsPaidByHand(): void
    {
        $this->build();
        $client = $this->fakeClient($this->collexiaHolds());

        $result = $this->cancelWith($client);

        $this->assertSame([['CTR000000001', 1, 'willemk']], $client->cancelled);
        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(0, $result['pending']);
        $this->assertNotNull($this->laneRow(1)['cancelled_at']);
        $this->assertNull($this->laneRow(2)['cancelled_at']);
        $this->assertNull($this->laneRow(3)['cancelled_at']);
    }

    public function testRunningItAgainNeverCancelsTwice(): void
    {
        $this->build();
        $client = $this->fakeClient($this->collexiaHolds());
        $this->cancelWith($client);
        $this->cancelWith($client);

        $this->assertCount(1, $client->cancelled);
    }

    public function testAnInstallmentThatIsNoLongerFullyPaidIsLeftToCollect(): void
    {
        $this->build(['paid_in_full' => false]);
        $client = $this->fakeClient($this->collexiaHolds());

        $result = $this->cancelWith($client);

        $this->assertSame([], $client->cancelled);
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(0, (int) $this->laneRow(1)['auto_cancel'], 'it must stop being treated as paid by hand');
        $this->assertNotEmpty($result['warnings']);
    }

    public function testNothingIsCancelledUntilEveryDateIsConfirmed(): void
    {
        $this->build(['applied' => false]);
        $client = $this->fakeClient($this->collexiaHolds());

        $result = $this->cancelWith($client);

        $this->assertSame([], $client->cancelled);
        $this->assertSame(1, $result['pending']);
    }

    public function testNothingIsCancelledWhileTheMandateIsNotRegistered(): void
    {
        $this->build();
        $this->leg['collexia_api_status'] = 'Load Pending';
        $client = $this->fakeClient($this->collexiaHolds());

        $this->cancelWith($client);
        $this->assertSame([], $client->cancelled);
    }

    public function testAnInstallmentAlreadyCancelledAtCollexiaIsJustRecorded(): void
    {
        $this->build();
        $client = $this->fakeClient($this->collexiaHolds([1 => 2]));

        $result = $this->cancelWith($client);

        $this->assertSame([], $client->cancelled, 'no second cancel call');
        $this->assertSame(1, $result['cancelled']);
        $this->assertNotNull($this->laneRow(1)['cancelled_at']);
    }

    public function testAnInstallmentCollexiaAlreadySentForPaymentIsNotTouchedButFlagged(): void
    {
        $this->build();
        $client = $this->fakeClient($this->collexiaHolds([1 => 4])); // Send For Payment

        $result = $this->cancelWith($client);

        $this->assertSame([], $client->cancelled);
        $this->assertNull($this->laneRow(1)['cancelled_at']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testAnInstallmentWhoseDateHasPassedIsNotTouchedButFlagged(): void
    {
        $this->build();
        $client = $this->fakeClient($this->collexiaHolds());

        $result = $this->cancelWith($client, '2027-11-17'); // today IS its collection date

        $this->assertSame([], $client->cancelled);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testItNeverCancelsEveryInstallmentOfAMandate(): void
    {
        $this->build(['flag_all' => true]);
        $client = $this->fakeClient($this->collexiaHolds());

        $result = $this->cancelWith($client);

        $this->assertSame([], $client->cancelled);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testARefusedCancelStaysPendingForTheNextRun(): void
    {
        $this->build();
        $failing = $this->fakeClient($this->collexiaHolds(), new CollexiaApiException([['code' => '10570', 'message' => 'Cannot cancel']], 400, 'Failed'));

        $result = $this->cancelWith($failing);

        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(1, $result['pending']);
        $this->assertNull($this->laneRow(1)['cancelled_at']);
        $this->assertNotEmpty($this->laneRow(1)['cancel_note']);

        // The retry (the cron) succeeds.
        $working = $this->fakeClient($this->collexiaHolds());
        $this->assertSame(1, $this->cancelWith($working)['cancelled']);
        $this->assertNotNull($this->laneRow(1)['cancelled_at']);
    }

    public function testTheFinalizerCancelsThePaidInstallmentRightAfterConfirmingTheMandate(): void
    {
        $this->build(['applied' => false]);
        $this->db->prepare("UPDATE debit_order_split_legs SET collexia_api_status = 'Load Pending', collexia_api_contract_reference = 'CTR000000001', collexia_api_synced_at = NOW() WHERE id = ?")
            ->execute([$this->leg['id']]);
        $client = $this->fakeClient($this->collexiaHolds());
        $debitOrder = (new DebitOrder())->find($this->debitOrderId);

        $result = (new CollexiaMandateFinalizer(fn () => $client))->finalizeDebitOrder($debitOrder, 'willemk');

        $this->assertSame(1, $result['registered']);
        $this->assertSame([['CTR000000001', 1, 'willemk']], $client->cancelled, 'only the paid-by-hand installment, nothing else');
        $this->assertNotNull($this->laneRow(1)['cancelled_at']);
        $this->assertNull($this->laneRow(2)['cancelled_at']);
        $this->assertNull($this->laneRow(3)['cancelled_at']);
    }

    public function testAMandateWithNothingFlaggedMakesNoCollexiaCall(): void
    {
        $this->build();
        $this->db->prepare('UPDATE debit_order_split_leg_installments SET auto_cancel = 0 WHERE leg_id = ?')->execute([$this->leg['id']]);
        $client = $this->fakeClient($this->collexiaHolds());

        $result = $this->cancelWith($client);
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame([], $client->cancelled);
    }
}
