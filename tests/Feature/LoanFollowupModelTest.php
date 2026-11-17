<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\LoanFollowup;
use App\Services\LoanFollowupFlow;
use PHPUnit\Framework\TestCase;

/** Storage of the loan questions against the local development database (fixture applications carry the reserved prefix APP-FUT-). */
class LoanFollowupModelTest extends TestCase
{
    private \PDO $db;
    private LoanFollowup $model;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->model = new LoanFollowup();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    private function purge(): void
    {
        $this->db->exec("DELETE FROM loan_followups WHERE application_no LIKE 'APP-FUT-%'");
        $this->db->exec("DELETE FROM loan_applications WHERE application_no LIKE 'APP-FUT-%'");
    }

    private function application(string $no, string $first, string $last, string $created, string $status = 'Submitted'): int
    {
        $this->db->prepare('INSERT INTO loan_applications (application_no, applicant_first_name, applicant_last_name, requested_amount, requested_term_months, status, created_at) VALUES (?, ?, ?, 1000, 1, ?, ?)')
            ->execute([$no, $first, $last, $status, $created]);
        return (int) $this->db->lastInsertId();
    }

    private function trackedCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM loan_followups WHERE application_no LIKE 'APP-FUT-%'")->fetchColumn();
    }

    public function testOnlyApplicationsFromTheTrackingDateOnAreTrackedAndOnlyOnce(): void
    {
        $this->application('APP-FUT-1', 'Maria', 'Test', '2026-10-30 09:00:00');
        $this->application('APP-FUT-2', 'Old', 'Timer', '2026-10-01 09:00:00');
        $this->application('APP-FUT-3', 'Rejected', 'One', '2026-11-02 09:00:00', 'Rejected');

        $this->assertSame(1, $this->model->syncApplications());
        $this->assertSame(0, $this->model->syncApplications(), 'running it again adds nothing');
        $this->assertSame(1, $this->trackedCount());
    }

    public function testTestApplicantsAreLeftOutByFullName(): void
    {
        $this->application('APP-FUT-1', 'Festus', 'Tester', '2026-11-02 09:00:00');
        $this->application('APP-FUT-2', 'Real', 'Person', '2026-11-02 09:00:00');

        $this->model->syncApplications(['festus  TESTER']);

        $names = array_column($this->model->openWithApplications(), 'application_no');
        $this->assertContains('APP-FUT-2', $names);
        $this->assertNotContains('APP-FUT-1', $names);
    }

    public function testAnswersInstalmentsAndTheLogAreStoredAndTheScheduleIsReadBack(): void
    {
        $id = $this->application('APP-FUT-1', 'Maria', 'Test', '2026-10-30 09:00:00');
        $this->model->syncApplications();
        $f = $this->db->query("SELECT id FROM loan_followups WHERE application_id = $id")->fetchColumn();
        $f = (int) $f;
        $userId = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

        $this->model->updateRecord($f, ['approved' => 'Yes', 'approved_amount' => 1500.5, 'term_months' => 2, 'disbursed' => 'Yes', 'disbursed_on' => '2026-10-30', 'pay_day' => 25, 'last_answered_by' => $userId ?: null]);
        $this->model->createInstalments($f, (new LoanFollowupFlow())->schedule(1500.5, 2, '2026-10-30', 25));
        $this->model->logAnswer($f, 'amount', 'Amount approved: N$ 1500.50', $userId ?: null);

        $rows = $this->model->instalments($f);
        $this->assertCount(2, $rows);
        $this->assertSame('2026-11-25', $rows[0]['due_date']);
        $this->assertNull($rows[0]['paid_amount']);
        $this->assertSame('APP-FUT-1', $this->model->application($f)['application_no']);
        $this->assertSame('Amount approved: N$ 1500.50', $this->model->answers($f)[0]['answer']);
        $this->assertContains($f, array_map('intval', array_column($this->model->disbursedWithApplications(), 'id')));

        $this->model->updateInstalment((int) $rows[0]['id'], ['paid_amount' => 500, 'paid_on' => '2026-11-26']);
        $this->assertSame('500.00', $this->model->instalment((int) $rows[0]['id'])['paid_amount']);
        $this->assertArrayHasKey($f, $this->model->instalmentsByFollowup());
    }

    public function testPaymentsAreKeptOneByOneWithTheirOwnDates(): void
    {
        $id = $this->application('APP-FUT-1', 'Maria', 'Test', '2026-10-30 09:00:00');
        $this->model->syncApplications();
        $f = (int) $this->db->query("SELECT id FROM loan_followups WHERE application_id = $id")->fetchColumn();
        $this->model->createInstalments($f, (new LoanFollowupFlow())->schedule(1000, 1, '2026-10-30', 25));
        $i = (int) $this->model->instalments($f)[0]['id'];

        $this->model->addPayment($i, 300, '2026-11-26', null);
        $this->model->addPayment($i, 1020, '2026-12-05', null);

        $p = $this->model->paymentsByInstalment($f)[$i];
        $this->assertSame(['2026-11-26', '2026-12-05'], array_column($p, 'paid_on'));
        $this->assertSame('300.00', $p[0]['amount']);

        $this->model->deleteInstalments($f);
        $this->assertSame([], $this->model->instalments($f));
        $this->assertSame([], $this->model->paymentsByInstalment($f), 'payments go with their instalments');
    }

    public function testClosingIsRefusedOnceTheMoneyIsPaidOutAndOnlyHappensOnce(): void
    {
        $a = $this->application('APP-FUT-1', 'Maria', 'Test', '2026-10-30 09:00:00');
        $b = $this->application('APP-FUT-2', 'Paul', 'Two', '2026-10-30 09:00:00');
        $this->model->syncApplications();
        $fa = (int) $this->db->query("SELECT id FROM loan_followups WHERE application_id = $a")->fetchColumn();
        $fb = (int) $this->db->query("SELECT id FROM loan_followups WHERE application_id = $b")->fetchColumn();
        $this->model->updateRecord($fb, ['disbursed' => 'Yes']);

        $this->assertTrue($this->model->closeUnlessPaidOut($fa, 'test', null));
        $this->assertFalse($this->model->closeUnlessPaidOut($fa, 'test', null), 'already closed');
        $this->assertFalse($this->model->closeUnlessPaidOut($fb, 'test', null), 'paid out');
        $this->assertSame('Open', $this->model->find($fb)['status']);
    }

    public function testTheRowCanBeLockedInsideATransactionAndTheSyncIsHarmlessWhenRunTwiceAtOnce(): void
    {
        $id = $this->application('APP-FUT-1', 'Maria', 'Test', '2026-10-30 09:00:00');
        $this->model->syncApplications();
        $f = (int) $this->db->query("SELECT id FROM loan_followups WHERE application_id = $id")->fetchColumn();

        $locked = $this->model->transaction(fn () => $this->model->lock($f));

        $this->assertSame('APP-FUT-1', $locked['application_no']);
        // another run that already read the same missing application inserts it again: nothing breaks and nothing is duplicated
        $stmt = $this->db->prepare('INSERT IGNORE INTO loan_followups (application_id, application_no) VALUES (?, ?)');
        $stmt->execute([$id, 'APP-FUT-1']);
        $this->assertSame(0, $stmt->rowCount());
        $this->assertSame(1, $this->trackedCount());
    }

    public function testAClosedFollowupIsNotListedAsOpen(): void
    {
        $id = $this->application('APP-FUT-1', 'Maria', 'Test', '2026-10-30 09:00:00');
        $this->model->syncApplications();
        $f = (int) $this->db->query("SELECT id FROM loan_followups WHERE application_id = $id")->fetchColumn();

        $this->model->updateRecord($f, ['status' => 'Closed', 'closed_reason' => 'test']);

        $this->assertNotContains('APP-FUT-1', array_column($this->model->openWithApplications(), 'application_no'));
    }
}
