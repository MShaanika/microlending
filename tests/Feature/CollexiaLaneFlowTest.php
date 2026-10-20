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

    public function testEachInstallmentListsEveryMandateThatCarriesIt(): void
    {
        $built = $this->buildLane();
        $laneRows = new DebitOrderSplitLegInstallment();

        // A second mandate (part 2) carrying only installments 2 and 3 of the loan.
        $legs = new DebitOrderSplitLeg();
        $secondId = $legs->upsert($this->debitOrderId, 2, 13.79, 2, null, '2026-12-17');
        $laneRows->add($secondId, 1, $this->scheduleIds[1], '2026-12-17', 13.79);
        $laneRows->add($secondId, 2, $this->scheduleIds[2], '2027-01-17', 13.79);

        $carriers = $laneRows->carriersBySchedule($this->debitOrderId);

        $this->assertCount(1, $carriers[$this->scheduleIds[0]], 'An unsplit installment has a single mandate');
        $this->assertCount(2, $carriers[$this->scheduleIds[1]], 'A split installment lists both mandates');
        $this->assertSame([1, 2], array_map(fn ($c) => (int) $c['split_no'], $carriers[$this->scheduleIds[1]]));
        $this->assertSame((int) $built['leg']['id'], (int) $carriers[$this->scheduleIds[0]][0]['leg_id']);
        $this->assertSame(2, (int) $carriers[$this->scheduleIds[2]][1]['mandate_installment_no'], 'Installment 3 of the loan is the second installment inside mandate 2');
    }

    private function pendingFakeClient(bool $loaded): object
    {
        return new class($loaded) {
            public array $updates = [];

            public function __construct(private bool $loaded)
            {
            }

            public function requestFinalFate(string $ref, string $user): array
            {
                return $this->loaded ? ['mandateLoaded' => true, 'mandateLoadedResponseCode' => '00'] : ['mandateLoaded' => false, 'mandateLoadedResponseCode' => '99'];
            }

            public function installmentRequest(string $ref): array
            {
                return ['installments' => [
                    ['intId' => 1, 'installmentNo' => 1, 'scheduledDate' => '20261117', 'installmentAmount' => 27.57, 'status' => 1],
                    ['intId' => 2, 'installmentNo' => 2, 'scheduledDate' => '20261217', 'installmentAmount' => 27.57, 'status' => 1],
                    ['intId' => 3, 'installmentNo' => 3, 'scheduledDate' => '20270117', 'installmentAmount' => 27.57, 'status' => 1],
                ]];
            }

            public function updateInstallment(array $installments, string $user): array
            {
                $this->updates = $installments;
                return [];
            }
        };
    }

    public function testFinalizerRegistersAPendingLaneAndSetsItsExactAmounts(): void
    {
        $built = $this->buildLane();
        (new DebitOrderSplitLeg())->updateState($this->debitOrderId, 1, ['collexia_api_status' => 'Load Pending', 'collexia_api_synced_at' => date('Y-m-d H:i:s')]);
        $fake = $this->pendingFakeClient(true);

        $result = (new \App\Services\CollexiaMandateFinalizer(fn () => $fake))->finalizeDebitOrder($built['debitOrder'], 'tester');

        $this->assertSame(1, $result['registered']);
        $this->assertSame(0, $result['pending']);
        $leg = (new DebitOrderSplitLeg())->activeForDebitOrder($this->debitOrderId)[0];
        $this->assertSame('Registered', $leg['collexia_api_status']);
        $this->assertSame([3], array_map(fn ($u) => $u['intId'], $fake->updates), 'Only the installment whose amount differs (27.56) is updated');
        $this->assertNotContains(null, array_column((new DebitOrderSplitLegInstallment())->forLeg((int) $leg['id']), 'applied_at'));
    }

    public function testFinalizerLeavesAMandateCollexiaHasNotConfirmedYetAsPending(): void
    {
        $built = $this->buildLane();
        (new DebitOrderSplitLeg())->updateState($this->debitOrderId, 1, ['collexia_api_status' => 'Load Pending', 'collexia_api_synced_at' => date('Y-m-d H:i:s')]);

        $result = (new \App\Services\CollexiaMandateFinalizer(fn () => $this->pendingFakeClient(false)))->finalizeDebitOrder($built['debitOrder'], 'tester');

        $this->assertSame(1, $result['pending']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('Load Pending', (new DebitOrderSplitLeg())->activeForDebitOrder($this->debitOrderId)[0]['collexia_api_status'], 'Just submitted, so it is not declared failed');
    }

    public function testFinalizerOnlyDeclaresFailureAfterItHasBeenPendingTooLong(): void
    {
        $built = $this->buildLane();
        (new DebitOrderSplitLeg())->updateState($this->debitOrderId, 1, ['collexia_api_status' => 'Load Pending', 'collexia_api_synced_at' => date('Y-m-d H:i:s', strtotime('-30 minutes'))]);

        $result = (new \App\Services\CollexiaMandateFinalizer(fn () => $this->pendingFakeClient(false)))->finalizeDebitOrder($built['debitOrder'], 'tester');

        $this->assertSame(1, $result['failed']);
        $this->assertSame('Load Failed', (new DebitOrderSplitLeg())->activeForDebitOrder($this->debitOrderId)[0]['collexia_api_status']);
    }

    private function refusingFakeClient(\Throwable $error): object
    {
        return new class($error) {
            public function __construct(private \Throwable $error)
            {
            }

            public function installmentRequest(string $ref): array
            {
                return ['installments' => [
                    ['intId' => 1, 'installmentNo' => 1, 'scheduledDate' => '20261117', 'installmentAmount' => 27.57, 'status' => 1],
                    ['intId' => 2, 'installmentNo' => 2, 'scheduledDate' => '20261217', 'installmentAmount' => 27.57, 'status' => 1],
                    ['intId' => 3, 'installmentNo' => 3, 'scheduledDate' => '20270117', 'installmentAmount' => 27.57, 'status' => 1],
                ]];
            }

            public function updateInstallment(array $installments, string $user): array
            {
                throw $this->error;
            }
        };
    }

    public function testACollexiaRefusalIsReportedAsARefusalButANetworkFailureIsNot(): void
    {
        $built = $this->buildLane();
        $refusal = new \App\Services\CollexiaApiException([['rcoId' => 10568, 'code' => 'E', 'message' => 'Update declined']], 400, 'declined');

        $refused = (new CollexiaLaneScheduleService(fn () => $this->refusingFakeClient($refusal)))->apply($built['leg'], $built['debitOrder'], 'tester');
        $this->assertFalse($refused['success']);
        $this->assertTrue($refused['rejected'], 'An API error means Collexia refused the change');

        $network = (new CollexiaLaneScheduleService(fn () => $this->refusingFakeClient(new \RuntimeException('Failed to reach the Collexia API'))))->apply($built['leg'], $built['debitOrder'], 'tester');
        $this->assertFalse($network['success']);
        $this->assertFalse($network['rejected'], 'A network failure is retried unchanged, never treated as a refusal');
    }

    public function testFinalizerReportsTheMandateCollexiaRefusedSoItCanBeReplaced(): void
    {
        $built = $this->buildLane();
        (new DebitOrderSplitLeg())->updateState($this->debitOrderId, 1, ['collexia_api_status' => 'Load Pending', 'collexia_api_synced_at' => date('Y-m-d H:i:s')]);
        $refusal = new \App\Services\CollexiaApiException([['rcoId' => 10568, 'code' => 'E', 'message' => 'Update declined']], 400, 'declined');
        $client = new class($refusal) {
            public function __construct(private \Throwable $error)
            {
            }

            public function requestFinalFate(string $ref, string $user): array
            {
                return ['mandateLoaded' => true];
            }

            public function installmentRequest(string $ref): array
            {
                return ['installments' => [['intId' => 1, 'installmentNo' => 1, 'scheduledDate' => '20261118', 'installmentAmount' => 27.57, 'status' => 1]]];
            }

            public function updateInstallment(array $installments, string $user): array
            {
                throw $this->error;
            }
        };

        $result = (new \App\Services\CollexiaMandateFinalizer(fn () => $client))->finalizeDebitOrder($built['debitOrder'], 'tester');

        $this->assertSame(1, $result['registered']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame((int) $built['leg']['id'], (int) $result['rejected'][0]['id']);
    }

    public function testTwoInstallmentsOfOneMandateCanSettleTheSameLoanInstallment(): void
    {
        $built = $this->buildLane();
        $this->db->prepare('DELETE FROM debit_order_split_leg_installments WHERE leg_id = ?')->execute([(int) $built['leg']['id']]);
        $rows = new DebitOrderSplitLegInstallment();
        $rows->add((int) $built['leg']['id'], 1, $this->scheduleIds[0], '2026-11-17', 13.78);
        $rows->add((int) $built['leg']['id'], 2, $this->scheduleIds[0], '2026-11-17', 13.79);
        $rows->add((int) $built['leg']['id'], 3, $this->scheduleIds[1], '2026-12-17', 27.57);

        $lookup = new CollexiaMandateLookupService();
        $mandate = $lookup->resolve($built['leg']['collexia_api_contract_reference']);
        $this->assertSame($this->scheduleIds[0], $lookup->scheduleIdFor($mandate, 1));
        $this->assertSame($this->scheduleIds[0], $lookup->scheduleIdFor($mandate, 2), 'Both parts settle the same loan installment');
        $this->assertSame($this->scheduleIds[1], $lookup->scheduleIdFor($mandate, 3));

        $carriers = $rows->carriersBySchedule($this->debitOrderId);
        $this->assertCount(2, $carriers[$this->scheduleIds[0]]);
        $this->assertSame((int) $built['leg']['id'], (int) $carriers[$this->scheduleIds[0]][0]['leg_id']);
        $this->assertSame((int) $built['leg']['id'], (int) $carriers[$this->scheduleIds[0]][1]['leg_id'], 'Same mandate carries both parts');
    }
    public function testNextUncoveredInstallmentSkipsEverythingALaneCarries(): void
    {
        $this->buildLane();
        $this->assertNull(
            (new DebitOrderSplitLeg())->nextUncoveredScheduleId($this->debitOrderId, $this->loanId),
            'Every unpaid installment is already carried by the lane'
        );
    }
    /** A fake Collexia holding one 3-installment mandate: cancelInstallment() flips an installment to Cancelled (2). */
    private function cancellableFake(int $mandateStatus = 2): object
    {
        return new class($mandateStatus) {
            public array $statuses = [1 => 1, 2 => 1, 3 => 1];

            public function __construct(public int $mandateStatus)
            {
            }

            public function cancelInstallment(string $ref, int $no, string $user): array
            {
                $this->statuses[$no] = 2;
                return [];
            }

            public function mandateEnquiry(array $filters): array
            {
                $dates = [1 => '20261117', 2 => '20261217', 3 => '20270117'];
                $amounts = [1 => 27.57, 2 => 27.57, 3 => 27.56];
                $installments = [];
                foreach ($this->statuses as $no => $status) {
                    $installments[] = ['noOfInstallment' => $no, 'status' => $status, 'scheduleDate' => $dates[$no], 'installmentAmount' => $amounts[$no]];
                }
                return ['mandate' => [['status' => $this->mandateStatus, 'noOfInstallments' => 3, 'installments' => $installments]]];
            }

            public function installmentRequest(string $ref): array
            {
                return ['installments' => [
                    ['intId' => 101, 'installmentNo' => 1, 'scheduledDate' => '20261117', 'installmentAmount' => 27.57, 'status' => $this->statuses[1]],
                    ['intId' => 102, 'installmentNo' => 2, 'scheduledDate' => '20261217', 'installmentAmount' => 27.57, 'status' => $this->statuses[2]],
                    ['intId' => 103, 'installmentNo' => 3, 'scheduledDate' => '20270117', 'installmentAmount' => 27.56, 'status' => $this->statuses[3]],
                ]];
            }

            public function updateInstallment(array $installments, string $user): array
            {
                return [];
            }
        };
    }

    private function debitOrderStatus(): string
    {
        return (string) $this->db->query('SELECT status FROM debit_orders WHERE id = ' . (int) $this->debitOrderId)->fetchColumn();
    }

    /** Collexia's three scenarios, through the app: in flight stays Active, one still live stays Active, the last cancelled makes the contract Completed. */
    public function testCancellingInstallmentsOneByOneCompletesTheContractOnlyWhenEveryInstallmentIsFinal(): void
    {
        $built = $this->buildLane();
        $fake = $this->cancellableFake();
        $service = new \App\Services\CollexiaInstallmentCancellationService(fn () => $fake);
        $ref = $built['leg']['collexia_api_contract_reference'];

        // Scenario A: nothing cancelled, every installment in flight.
        (new \App\Services\CollexiaMandateStatusSyncService(fn () => $fake))->syncDebitOrder($built['debitOrder']);
        $this->assertSame('Active', $this->debitOrderStatus());

        // Scenario B: installments cancelled while at least one is still in flight (Collexia's "Use Case 2").
        $first = $service->cancel($built['debitOrder'], $ref, 1, 'tester', 0);
        $this->assertFalse($first['completed']);
        $this->assertSame('Active', $this->debitOrderStatus());
        $second = $service->cancel($built['debitOrder'], $ref, 2, 'tester', 0);
        $this->assertFalse($second['completed']);
        $this->assertSame('Active', $this->debitOrderStatus());

        // Scenario C: the last live installment is cancelled, so every installment is final.
        $last = $service->cancel($built['debitOrder'], $ref, 3, 'tester', 0);
        $this->assertTrue($last['completed']);
        $this->assertSame('Active', $last['status_before']);
        $this->assertSame('Completed', $last['status_after']);
        $this->assertSame('Completed', $this->debitOrderStatus());
        $this->assertNull($last['sync_error']);
    }

    public function testACancelledMandateStaysCancelledEvenThoughEveryInstallmentIsFinal(): void
    {
        $built = $this->buildLane();
        $fake = $this->cancellableFake(4);
        $fake->statuses = [1 => 2, 2 => 2, 3 => 2];

        (new \App\Services\CollexiaMandateStatusSyncService(fn () => $fake))->syncDebitOrder($built['debitOrder']);
        $this->assertSame('Cancelled', $this->debitOrderStatus());
    }
}
