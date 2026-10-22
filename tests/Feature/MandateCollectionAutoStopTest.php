<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\CollexiaSetting;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;
use App\Services\CollexiaCollectionStopper;
use App\Services\MandateCollectionAutoStop;
use App\Support\SplitLanePlanner;
use PHPUnit\Framework\TestCase;

/**
 * Right after a manual payment is recorded, the collection it made unnecessary is cancelled AUTOMATICALLY, with a message. Collexia is
 * replaced by a fake, so nothing leaves this machine; local dev database, throwaway rows removed afterwards.
 */
class MandateCollectionAutoStopTest extends TestCase
{
    private \PDO $db;
    private array $template = [];
    private ?int $loanId = null;
    private ?int $debitOrderId = null;
    private array $scheduleIds = [];
    private array $laneRowIds = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->template = $this->db->query('SELECT borrower_id, product_id, plan_id, branch_id FROM loans ORDER BY id LIMIT 1')->fetch() ?: [];
        if (!$this->template || !$this->db->query("SHOW COLUMNS FROM debit_order_split_leg_installments LIKE 'cancelled_at'")->fetch()) {
            $this->markTestSkipped('Local database is not ready for this test.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->loanId) {
            $this->db->exec("DELETE FROM audit_logs WHERE metadata LIKE '%\"loan_id\":" . $this->loanId . "%'");
            $this->db->exec('DELETE FROM loan_status_history WHERE loan_id = ' . $this->loanId);
        }
        if ($this->debitOrderId) {
            $this->db->prepare('DELETE FROM debit_order_split_leg_installments WHERE leg_id IN (SELECT id FROM debit_order_split_legs WHERE debit_order_id = ?)')->execute([$this->debitOrderId]);
            $this->db->prepare('DELETE FROM debit_order_split_legs WHERE debit_order_id = ?')->execute([$this->debitOrderId]);
            $this->db->prepare('DELETE FROM debit_orders WHERE id = ?')->execute([$this->debitOrderId]);
        }
        if ($this->loanId) {
            $this->db->exec('DELETE FROM loan_schedules WHERE loan_id = ' . $this->loanId);
            $this->db->exec('DELETE FROM loans WHERE id = ' . $this->loanId);
        }
    }

    private function makeLoan(array $rows, bool $split): void
    {
        $this->db->prepare(
            "INSERT INTO loans (loan_no, borrower_id, product_id, plan_id, branch_id, loan_status, principal_amount, installment_amount, payment_day, start_date)
             VALUES (?, ?, ?, ?, ?, 'Active', 1000, 100, 17, '2027-01-17')"
        )->execute(['LN-AUTO-' . uniqid(), $this->template['borrower_id'], $this->template['product_id'], $this->template['plan_id'], $this->template['branch_id']]);
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
             VALUES (?, ?, ?, 'FNB', '123', 17, 100, '2027-01-17', 'Active', 1, 1, 'FN', 3, ?, 'Registered', ?)"
        )->execute([$this->template['borrower_id'], $this->loanId, 'DO-AUTO-' . uniqid(), $split ? 1 : 0, $split ? null : 'CTRSINGLE00001']);
        $this->debitOrderId = (int) $this->db->lastInsertId();

        if ($split) {
            $plan = [];
            foreach ($rows as $i => [$due, $amount]) {
                $plan[] = ['schedule_id' => $this->scheduleIds[$i + 1], 'loan_installment_no' => $i + 1, 'date' => $due, 'amounts' => [$amount]];
            }
            $lane = SplitLanePlanner::planSingle($plan)[0];
            $legId = (new DebitOrderSplitLeg())->upsert($this->debitOrderId, 1, $lane['amount'], 1, null, $lane['first_date']);
            $laneModel = new DebitOrderSplitLegInstallment();
            foreach ($lane['installments'] as $item) {
                $this->laneRowIds[$item['installment_no']] = $laneModel->add($legId, $item['installment_no'], $item['schedule_id'], $item['date'], $item['amount']);
                $laneModel->markApplied($this->laneRowIds[$item['installment_no']]);
            }
            $this->db->prepare("UPDATE debit_order_split_legs SET collexia_api_status = 'Registered', collexia_api_contract_reference = 'CTRLANE0000001' WHERE id = ?")->execute([$legId]);
        }
    }

    private function fake(array $dates, ?\Throwable $cancelThrows = null): object
    {
        $installments = [];
        foreach (array_values($dates) as $i => $d) {
            $installments[] = ['installmentNo' => $i + 1, 'scheduledDate' => str_replace('-', '', $d), 'installmentAmount' => 100.0, 'status' => 1, 'intId' => 7000 + $i];
        }
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

    private function auto(object $client, bool $switchedOn = true): MandateCollectionAutoStop
    {
        $settings = $this->createMock(CollexiaSetting::class);
        $settings->method('autoStopCollections')->willReturn($switchedOn);
        $settings->method('isEnabled')->willReturn(true);
        return new MandateCollectionAutoStop(null, new CollexiaCollectionStopper(fn () => $client), $settings);
    }

    public function testAPaymentThatClearsAScheduledInstallmentCancelsItsCollectionAutomaticallyWithAMessage(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0], ['2027-03-17', 100, 0]], true);
        $client = $this->fake(['2027-01-17', '2027-02-17', '2027-03-17']);

        $r = $this->auto($client)->afterPayment($this->loanId, 1, 'willemk', '2026-12-01');

        $this->assertSame([['CTRLANE0000001', 1]], $client->cancelled, 'only the paid installment');
        $this->assertSame(1, $r['cancelled']);
        $this->assertSame([], $r['problems']);
        $this->assertStringContainsString('cancelled automatically', $r['messages'][0]);
        $this->assertStringContainsString('Installment #1', $r['messages'][0]);

        $notes = $this->db->query('SELECT notes FROM loan_status_history WHERE loan_id = ' . $this->loanId)->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('AUTOMATIC', $notes[0]);
        $audit = $this->db->query("SELECT COUNT(*) FROM audit_logs WHERE metadata LIKE '%auto_stop_collection_after_manual_payment%' AND metadata LIKE '%\"loan_id\":" . $this->loanId . "%'")->fetchColumn();
        $this->assertSame(1, (int) $audit);
    }

    public function testNothingHappensWhenTheSwitchIsOff(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0]], true);
        $client = $this->fake(['2027-01-17', '2027-02-17']);

        $r = $this->auto($client, false)->afterPayment($this->loanId, 1, 'willemk', '2026-12-01');

        $this->assertSame([], $client->cancelled);
        $this->assertFalse($r['attempted']);
    }

    public function testACollectionDueTomorrowIsNotCancelledButIsReported(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0]], true);
        $client = $this->fake(['2027-01-17', '2027-02-17']);

        $r = $this->auto($client)->afterPayment($this->loanId, 1, 'willemk', '2027-01-16');

        $this->assertSame([], $client->cancelled);
        $this->assertCount(1, $r['problems']);
        $this->assertStringContainsString('can no longer be stopped', $r['problems'][0]);
        $this->assertStringContainsString('refund the overpayment', $r['problems'][0]);
    }

    public function testAPaymentOnALoanWithNothingScheduledToStopDoesNothing(): void
    {
        $this->makeLoan([['2027-01-17', 100, 0], ['2027-02-17', 100, 0]], true);
        $client = $this->fake(['2027-01-17', '2027-02-17']);

        $r = $this->auto($client)->afterPayment($this->loanId, 1, 'willemk', '2026-12-01');

        $this->assertSame([], $client->cancelled);
        $this->assertFalse($r['attempted']);
        $this->assertSame([], $r['messages']);
    }

    public function testAFailureIsReturnedAsAMessageAndNeverThrown(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 0]], true);
        $client = $this->fake(['2027-01-17', '2027-02-17'], new \RuntimeException('Collexia is unavailable'));

        $r = $this->auto($client)->afterPayment($this->loanId, 1, 'willemk', '2026-12-01');

        $this->assertSame(0, $r['cancelled']);
        $this->assertCount(1, $r['problems']);
        $this->assertStringContainsString('red notice', $r['problems'][0], 'points at the button that can retry');
    }

    public function testASingleRecurringMandateHasItsSurplusCollectionsCancelledAutomatically(): void
    {
        $this->makeLoan([['2027-01-17', 100, 100], ['2027-02-17', 100, 100], ['2027-03-17', 100, 0], ['2027-04-17', 100, 0]], false);
        $client = $this->fake(['2027-01-17', '2027-02-17', '2027-03-17', '2027-04-17']);

        $r = $this->auto($client)->afterPayment($this->loanId, 1, 'willemk', '2026-12-01');

        $numbers = array_column($client->cancelled, 1);
        sort($numbers);
        $this->assertSame([3, 4], $numbers, 'the last two, never the ones still needed');
        $this->assertSame(2, $r['cancelled']);
        $this->assertStringContainsString('cancelled automatically', $r['messages'][0]);
    }
}
