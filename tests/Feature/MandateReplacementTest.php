<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\DebitOrder;
use App\Services\LoanTimelineService;
use App\Services\MandateReplacementService;
use PHPUnit\Framework\TestCase;

/**
 * A loan rescheduled underneath a live Collexia mandate: the debit order is marked out of step, the replacement is a deliberate
 * cancel that is recorded on the loan's history and audit trail, and the loan's activity page gathers it all. Runs against the local
 * dev database like the other Feature tests, with its own throwaway rows removed afterwards; Collexia is replaced by a fake.
 */
class MandateReplacementTest extends TestCase
{
    private \PDO $db;
    private array $loanIds = [];
    private array $debitOrderIds = [];
    private array $rescheduleIds = [];
    private array $auditIds = [];
    private array $template = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        if (!$this->db->query("SHOW COLUMNS FROM debit_orders LIKE 'mandate_stale_since'")->fetch()) {
            $this->markTestSkipped('database/debit_order_mandate_stale.sql has not been applied to this database.');
        }
        $this->template = $this->db->query('SELECT borrower_id, product_id, plan_id, branch_id FROM loans ORDER BY id LIMIT 1')->fetch() ?: [];
        if (!$this->template) {
            $this->markTestSkipped('No loan in the local database to borrow foreign keys from.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->auditIds as $id) {
            $this->db->exec('DELETE FROM audit_logs WHERE id = ' . (int) $id);
        }
        foreach ($this->rescheduleIds as $id) {
            $this->db->exec('DELETE FROM loan_reschedules WHERE id = ' . (int) $id);
        }
        foreach ($this->debitOrderIds as $id) {
            $this->db->exec('DELETE FROM debit_orders WHERE id = ' . (int) $id);
        }
        foreach ($this->loanIds as $id) {
            $this->db->exec('DELETE FROM audit_logs WHERE metadata LIKE \'%"loan_id":' . (int) $id . '%\' OR description LIKE \'%HARNESS-MR%\'');
            $this->db->exec('DELETE FROM loan_status_history WHERE loan_id = ' . (int) $id);
            $this->db->exec('DELETE FROM loan_schedules WHERE loan_id = ' . (int) $id);
            $this->db->exec('DELETE FROM loans WHERE id = ' . (int) $id);
        }
    }

    private function makeLoan(): array
    {
        $no = 'HARNESS-MR-' . uniqid();
        $this->db->prepare(
            "INSERT INTO loans (loan_no, borrower_id, product_id, plan_id, branch_id, loan_status, principal_amount, installment_amount, payment_day, start_date)
             VALUES (?, ?, ?, ?, ?, 'Active', 1000, 100, 17, '2027-01-17')"
        )->execute([$no, $this->template['borrower_id'], $this->template['product_id'], $this->template['plan_id'], $this->template['branch_id']]);
        $id = (int) $this->db->lastInsertId();
        $this->loanIds[] = $id;
        return ['id' => $id, 'loan_no' => $no];
    }

    private function makeDebitOrder(int $loanId, string $apiStatus = 'Registered', string $status = 'Active'): int
    {
        $this->db->prepare(
            "INSERT INTO debit_orders (borrower_id, loan_id, debit_order_no, bank_name, account_number, debit_day, debit_amount, start_date, status, id_type, account_type, bank_code, no_of_days_tracking, split_enabled, collexia_api_status)
             VALUES (?, ?, ?, 'FNB', '123', 17, 100, '2027-02-17', ?, 1, 1, 'FN', 3, 0, ?)"
        )->execute([$this->template['borrower_id'], $loanId, 'DO-MR-' . uniqid(), $status, $apiStatus]);
        $id = (int) $this->db->lastInsertId();
        $this->debitOrderIds[] = $id;
        return $id;
    }

    private function makeReschedule(int $loanId, string $status = 'Implemented'): int
    {
        $this->db->prepare(
            "INSERT INTO loan_reschedules (loan_id, borrower_id, branch_id, reschedule_no, request_date, effective_date, old_installment_amount, new_installment_amount, old_term_months, new_term_months, reason, status, implemented_at)
             VALUES (?, ?, ?, ?, '2027-03-01', '2027-04-01', 100, 80, 12, 15, 'Lost income', ?, NOW())"
        )->execute([$loanId, $this->template['borrower_id'], $this->template['branch_id'], 'RSC-MR-' . uniqid(), $status]);
        $id = (int) $this->db->lastInsertId();
        $this->rescheduleIds[] = $id;
        return $id;
    }

    // --- out of step ---

    public function testALiveMandateIsMarkedOutOfStepWhenItsLoanIsRescheduled(): void
    {
        $loan = $this->makeLoan();
        $do = $this->makeDebitOrder($loan['id']);
        $rs = $this->makeReschedule($loan['id']);
        $model = new DebitOrder();

        $this->assertNotNull($model->liveMandateForLoan($loan['id']));
        $this->assertNull($model->outOfStepForLoan($loan['id']), 'not flagged until the reschedule is implemented');

        $marked = $model->markMandatesOutOfStep($loan['id'], $rs, 'The loan was rescheduled.');
        $this->assertSame([$do], array_map('intval', array_column($marked, 'id')));

        $flag = $model->outOfStepForLoan($loan['id']);
        $this->assertSame($do, (int) $flag['id']);
        $this->assertSame($rs, (int) $flag['mandate_stale_reschedule_id']);
        $this->assertContains($do, array_map('intval', array_column($model->outOfStepAll(), 'id')));
    }

    public function testANeverPlacedOrAlreadyCancelledDebitOrderIsNotFlagged(): void
    {
        $loan = $this->makeLoan();
        $this->makeDebitOrder($loan['id'], 'Not Placed');
        $loan2 = $this->makeLoan();
        $this->makeDebitOrder($loan2['id'], 'Cancelled', 'Cancelled');
        $rs = $this->makeReschedule($loan['id']);
        $model = new DebitOrder();

        $this->assertSame([], $model->markMandatesOutOfStep($loan['id'], $rs, 'x'), 'nothing live at Collexia, so nothing is out of step');
        $this->assertSame([], $model->markMandatesOutOfStep($loan2['id'], $rs, 'x'));
        $this->assertNull($model->outOfStepForLoan($loan['id']));
    }

    // --- replacing ---

    private function service(callable $canceller): MandateReplacementService
    {
        return new MandateReplacementService($canceller);
    }

    public function testCancellingForReplacementClosesTheDebitOrderAndRecordsItEverywhere(): void
    {
        $loan = $this->makeLoan();
        $do = $this->makeDebitOrder($loan['id']);
        $rs = $this->makeReschedule($loan['id']);
        $model = new DebitOrder();
        $model->markMandatesOutOfStep($loan['id'], $rs, 'The loan was rescheduled (RSC).');
        $row = $model->find($do);
        $calls = [];

        $result = $this->service(function (array $d) use (&$calls) {
            $calls[] = (int) $d['id'];
            return ['success' => true, 'message' => 'Mandate cancelled at Collexia.', 'attempted' => true];
        })->cancelForReplacement($row, 'Replaced to follow the new schedule', 1);

        $this->assertTrue($result['success']);
        $this->assertSame([$do], $calls);
        $this->assertSame('Cancelled', $model->find($do)['status'], 'closed so a new debit order can be registered');
        $this->assertNull($model->outOfStepForLoan($loan['id']), 'no longer live, so the red notice goes');

        $history = $this->db->query('SELECT notes FROM loan_status_history WHERE loan_id = ' . $loan['id'])->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $history);
        $this->assertStringContainsString('Replaced to follow the new schedule', $history[0]);

        $audit = $this->db->query("SELECT description, metadata FROM audit_logs WHERE metadata LIKE '%\"debit_order_id\":" . $do . "%' AND metadata LIKE '%\"loan_id\":" . $loan['id'] . "%'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($audit, 'the cancellation is in the audit trail with the loan id');
        $this->assertStringContainsString('"debit_order_id":' . $do, $audit[0]['metadata']);
    }

    public function testARefusedCancelChangesNothingButIsStillRecorded(): void
    {
        $loan = $this->makeLoan();
        $do = $this->makeDebitOrder($loan['id']);
        $model = new DebitOrder();

        $result = $this->service(fn (array $d) => ['success' => false, 'message' => 'Collexia is unavailable.', 'attempted' => true])
            ->cancelForReplacement($model->find($do), 'Replacing it', 1);

        $this->assertFalse($result['success']);
        $this->assertSame('Active', $model->find($do)['status'], 'still live: nothing was cancelled, so nothing may be marked cancelled');
        $notes = $this->db->query('SELECT notes FROM loan_status_history WHERE loan_id = ' . $loan['id'])->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('did not accept', $notes[0]);
    }

    public function testAnAlreadyClosedDebitOrderIsNotCancelledAgain(): void
    {
        $loan = $this->makeLoan();
        $do = $this->makeDebitOrder($loan['id'], 'Cancelled', 'Cancelled');
        $called = false;

        $result = $this->service(function () use (&$called) { $called = true; return ['success' => true, 'message' => '', 'attempted' => true]; })
            ->cancelForReplacement((new DebitOrder())->find($do), 'Replacing it', 1);

        $this->assertFalse($result['success']);
        $this->assertFalse($called, 'Collexia must not be asked to cancel something that is already cancelled');
    }

    // --- the loan's activity page ---

    public function testTheActivityTimelineGathersEverySourceNewestFirstAndMatchesWholeLoanNumbersOnly(): void
    {
        $loan = $this->makeLoan();
        $other = $this->makeLoan();
        $this->makeDebitOrder($loan['id']);
        $this->makeReschedule($loan['id']);
        $this->db->prepare("INSERT INTO loan_status_history (loan_id, old_status, new_status, notes, changed_at) VALUES (?, 'Active', 'Active', 'A note', '2030-01-01 10:00:00')")->execute([$loan['id']]);

        $own = $this->db->prepare("INSERT INTO audit_logs (action, module_name, description, metadata) VALUES ('Update', 'Loans', ?, ?)");
        $own->execute(['A structured entry', json_encode(['loan_id' => $loan['id']])]);
        $this->auditIds[] = (int) $this->db->lastInsertId();
        $own->execute(['Edited loan #' . $loan['id'] . ' by hand', null]);
        $this->auditIds[] = (int) $this->db->lastInsertId();
        // Another loan whose id merely STARTS with this one's digits must not leak in.
        $own->execute(['Edited loan #' . $loan['id'] . '9 by hand', json_encode(['loan_id' => (int) ($loan['id'] . '9')])]);
        $this->auditIds[] = (int) $this->db->lastInsertId();

        $events = LoanTimelineService::forLoan($loan['id'], null, 0);   // offset pinned: the test asserts exact times
        $kinds = array_unique(array_column($events, 'kind'));
        foreach (['loan', 'status', 'reschedule', 'debit_order', 'audit'] as $expected) {
            $this->assertContains($expected, $kinds, "the timeline has $expected entries");
        }

        $titles = implode(' | ', array_column($events, 'title') + array_column($events, 'detail'));
        $details = implode(' | ', array_column($events, 'detail'));
        $this->assertStringContainsString('A structured entry', $details);
        $this->assertStringContainsString('Edited loan #' . $loan['id'] . ' by hand', $details);
        $this->assertStringNotContainsString('Edited loan #' . $loan['id'] . '9 by hand', $details, 'whole loan numbers only');

        $sorted = array_column($events, 'at');
        $copy = $sorted;
        rsort($copy);
        $this->assertSame($copy, $sorted, 'newest first');
        $this->assertSame('2030-01-01 10:00:00', $events[0]['at']);
        $this->assertNotEmpty($other);
        $this->assertNotEmpty($titles);
    }

    public function testEventsStampedByTheTwoClocksLineUpOnTheApplicationsClock(): void
    {
        // Loan 9 on production: the database clock runs 6 hours behind the application's. The same payment was logged by the application at
        // 12:10 and by the audit trail (database clock) at 06:10 -- two entries, 6 hours apart. On one clock they must sit together.
        $loan = $this->makeLoan();
        $this->db->prepare("INSERT INTO payments (loan_id, borrower_id, branch_id, payment_no, payment_date, payment_source, amount_received, status, posted_at)
                            SELECT id, borrower_id, branch_id, ?, '2030-01-01', 'Cash', 100, 'Posted', '2030-01-01 12:10:45' FROM loans WHERE id = ?")
            ->execute(['PMT-CLOCK-' . uniqid(), $loan['id']]);
        $this->db->prepare("INSERT INTO audit_logs (action, module_name, description, metadata, created_at) VALUES ('Create', 'Collections', 'Recorded payment for the clock test', ?, '2030-01-01 06:10:45')")
            ->execute([json_encode(['loan_id' => $loan['id']])]);
        $this->auditIds[] = (int) $this->db->lastInsertId();

        $events = LoanTimelineService::forLoan($loan['id'], null, 6 * 3600);
        $payment = array_values(array_filter($events, fn ($e) => $e['kind'] === 'payment'))[0];
        $audit = array_values(array_filter($events, fn ($e) => $e['kind'] === 'audit' && str_contains($e['detail'], 'clock test')))[0];

        $this->assertSame('2030-01-01 12:10:45', $payment['at'], 'application-stamped: unchanged');
        $this->assertSame('2030-01-01 12:10:45', $audit['at'], 'database-stamped: shifted onto the application clock');
        $this->db->exec("DELETE FROM payments WHERE payment_no LIKE 'PMT-CLOCK-%'");
    }
}
