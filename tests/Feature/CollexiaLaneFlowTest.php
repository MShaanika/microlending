<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;
use App\Services\CollexiaLaneScheduleService;
use App\Services\CollexiaMandateLookupService;
use App\Support\SplitLanePlanner;
use PHPUnit\Framework\TestCase;

/**
 * End to end for a split LANE (one Collexia mandate carrying several loan
 * installments): a collected "installment 2 of this mandate" resolves to the
 * right loan installment, and the post-registration step moves ONLY the
 * installments whose date or amount differ -- against a fake Collexia client,
 * so nothing leaves this machine. Runs against the local dev database like
 * every other Feature test here, with its own throwaway rows removed
 * afterwards.
 */
class CollexiaLaneFlowTest extends TestCase
{
    private \PDO $db;
    private ?int $loanId = null;
    private ?int $debitOrderId = null;
    /** @var int[] */
    private array $scheduleIds = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
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

    /** @return array{leg: array, debitOrder: array} */
    private function buildLane(): array
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

        $rows = [['2026-11-17', 27.57], ['2026-12-17', 27.57], ['2027-01-17', 27.56]];
        foreach ($rows as $i => [$due, $amount]) {
            $this->db->prepare(
                "INSERT INTO loan_schedules (loan_id, installment_no, due_date, total_due, total_paid, status, namfisa_levy_due, duty_stamp_due, namfisa_levy_paid, duty_stamp_paid)
                 VALUES (?, ?, ?, ?, 0, 'Pending', 0, 0, 0, 0)"
            )->execute([$this->loanId, $i + 2, $due, $amount]);
            $this->scheduleIds[] = (int) $this->db->lastInsertId();
        }

        $this->db->prepare(
            "INSERT INTO debit_orders (borrower_id, loan_id, debit_order_no, bank_name, account_number, debit_day, debit_amount, start_date, status, id_type, account_type, bank_code, no_of_days_tracking, split_enabled)
             VALUES (?, ?, ?, 'FNB', '123', 17, 27.57, '2026-11-17', 'Active', 1, 1, '250655', 3, 1)"
        )->execute([$template['borrower_id'], $this->loanId, 'DO-TEST-' . uniqid()]);
        $this->debitOrderId = (int) $this->db->lastInsertId();

        $lanes = SplitLanePlanner::plan([
            ['schedule_id' => $this->scheduleIds[0], 'loan_installment_no' => 2, 'date' => '2026-11-17', 'amounts' => [27.57]],
            ['schedule_id' => $this->scheduleIds[1], 'loan_installment_no' => 3, 'date' => '2026-12-17', 'amounts' => [27.57]],
            ['schedule_id' => $this->scheduleIds[2], 'loan_installment_no' => 4, 'date' => '2027-01-17', 'amounts' => [27.56]],
        ]);
        $this->assertCount(1, $lanes);

        $legs = new DebitOrderSplitLeg();
        $laneRows = new DebitOrderSplitLegInstallment();
        $legId = $legs->upsert($this->debitOrderId, 1, $lanes[0]['amount'], 1, null, $lanes[0]['first_date']);
        foreach ($lanes[0]['installments'] as $item) {
            $laneRows->add($legId, $item['installment_no'], $item['schedule_id'], $item['date'], $item['amount']);
        }
        $legs->updateState($this->debitOrderId, 1, ['collexia_api_contract_reference' => 'LT' . substr(uniqid(), -10), 'collexia_api_status' => 'Registered']);

        return [
            'leg' => $this->db->query('SELECT * FROM debit_order_split_legs WHERE id = ' . $legId)->fetch(),
            'debitOrder' => $this->db->query('SELECT * FROM debit_orders WHERE id = ' . $this->debitOrderId)->fetch(),
        ];
    }

    public function testACollectedInstallmentNumberResolvesToTheRightLoanInstallment(): void
    {
        $built = $this->buildLane();
        $lookup = new CollexiaMandateLookupService();
        $mandate = $lookup->resolve($built['leg']['collexia_api_contract_reference']);

        $this->assertNotNull($mandate);
        $this->assertNull($mandate['schedule_id'], 'A lane has no single schedule of its own');
        $this->assertSame($this->scheduleIds[0], $lookup->scheduleIdFor($mandate, 1));
        $this->assertSame($this->scheduleIds[1], $lookup->scheduleIdFor($mandate, 2));
        $this->assertSame($this->scheduleIds[2], $lookup->scheduleIdFor($mandate, 3));
        $this->assertNull($lookup->scheduleIdFor($mandate, 4), 'An installment number the lane never carried must not guess');
    }

    public function testApplyMovesOnlyTheInstallmentsThatDiffer(): void
    {
        $built = $this->buildLane();
        // Collexia loaded the monthly pattern at one amount: installment 1's
        // date and amount are right, installment 2 is a day off, installment 3
        // has the wrong amount.
        $fake = new class {
            public array $updates = [];

            public function installmentRequest(string $ref): array
            {
                return ['installments' => [
                    ['intId' => 101, 'installmentNo' => 1, 'scheduledDate' => '20261117', 'installmentAmount' => 27.57, 'status' => 1],
                    ['intId' => 102, 'installmentNo' => 2, 'scheduledDate' => '20261218', 'installmentAmount' => 27.57, 'status' => 1],
                    ['intId' => 103, 'installmentNo' => 3, 'scheduledDate' => '20270117', 'installmentAmount' => 27.57, 'status' => 1],
                ]];
            }

            public function updateInstallment(array $installments, string $user): array
            {
                $this->updates = $installments;
                return [];
            }
        };

        $service = new CollexiaLaneScheduleService(fn () => $fake);
        $result = $service->apply($built['leg'], $built['debitOrder'], 'tester');

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame([102, 103], array_column($fake->updates, 'intId'));
        $this->assertSame('20261217', $fake->updates[0]['scheduledDate']);
        $this->assertSame(27.56, $fake->updates[1]['installmentAmount']);

        // Everything is now confirmed, so a second run does nothing at all.
        $again = $service->apply($built['leg'], $built['debitOrder'], 'tester');
        $this->assertSame(0, $again['updated']);
        $applied = array_column((new DebitOrderSplitLegInstallment())->forLeg((int) $built['leg']['id']), 'applied_at');
        $this->assertNotContains(null, $applied);
    }

    public function testAFailedUpdateLeavesTheLaneUnappliedSoItIsRetried(): void
    {
        $built = $this->buildLane();
        $fake = new class {
            public function installmentRequest(string $ref): array
            {
                return ['installments' => [
                    ['intId' => 1, 'installmentNo' => 1, 'scheduledDate' => '20261118', 'installmentAmount' => 27.57, 'status' => 1],
                ]];
            }

            public function updateInstallment(array $installments, string $user): array
            {
                throw new \RuntimeException('Collexia is unavailable');
            }
        };

        $result = (new CollexiaLaneScheduleService(fn () => $fake))->apply($built['leg'], $built['debitOrder'], 'tester');

        $this->assertFalse($result['success']);
        $this->assertSame('Collexia is unavailable', $result['message']);
        $applied = array_column((new DebitOrderSplitLegInstallment())->forLeg((int) $built['leg']['id']), 'applied_at');
        $this->assertSame([null, null, null], $applied, 'Nothing is marked applied after a failure');
    }

    public function testNextUncoveredInstallmentSkipsEverythingALaneCarries(): void
    {
        $this->buildLane();
        $this->assertNull(
            (new DebitOrderSplitLeg())->nextUncoveredScheduleId($this->debitOrderId, $this->loanId),
            'Every unpaid installment is already carried by the lane'
        );
    }
}
