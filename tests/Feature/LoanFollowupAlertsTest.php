<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\LoanFollowupAlerts;
use PHPUnit\Framework\TestCase;

/** The Loan Questions alerts against the local development database (fixture applications carry the reserved prefix APP-FUA-). */
class LoanFollowupAlertsTest extends TestCase
{
    private \PDO $db;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->purge();
        $this->userId = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    private function purge(): void
    {
        $this->db->exec("DELETE FROM notification_queue WHERE source_module = 'Loan Questions' AND (source_id >= 900000000 OR subject LIKE '%APP-FUA-%' OR (source_table = 'loan_followups_daily' AND message LIKE '%APP-FUA-%'))");
        $this->db->exec("DELETE FROM loan_followups WHERE application_no LIKE 'APP-FUA-%'");
        $this->db->exec("DELETE FROM loan_applications WHERE application_no LIKE 'APP-FUA-%'");
    }

    private function application(string $no, string $first, string $last, string $status = 'Submitted'): int
    {
        $this->db->prepare('INSERT INTO loan_applications (application_no, applicant_first_name, applicant_last_name, requested_amount, requested_term_months, status, created_at) VALUES (?, ?, ?, 1000, 1, ?, NOW())')
            ->execute([$no, $first, $last, $status]);
        return (int) $this->db->lastInsertId();
    }

    private function alerts(): LoanFollowupAlerts
    {
        $id = $this->userId;
        return new LoanFollowupAlerts(static fn (): array => [
            ['id' => $id, 'name' => 'Staff One', 'email' => 'staff.one@example.invalid'],
            ['id' => $id, 'name' => 'No Email', 'email' => ''],
        ]);
    }

    /** @return array<int, array<string,mixed>> */
    private function queued(string $like): array
    {
        $s = $this->db->prepare("SELECT * FROM notification_queue WHERE source_module = 'Loan Questions' AND (subject LIKE ? OR message LIKE ?)");
        $s->execute([$like, $like]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testANewApplicationQueuesOneEmailForEveryoneWithAnAddressAndNeverTwice(): void
    {
        $appId = $this->application('APP-FUA-1', "Maria\nInjected: header", 'Test');
        $this->assertSame(1, $this->alerts()->newApplication($appId, 'APP-FUA-1', "Maria\nInjected: header Test"), 'the person without an e-mail address is skipped');
        $this->assertSame(0, $this->alerts()->newApplication($appId, 'APP-FUA-1', 'Maria Test'), 'the same application never alerts the same person twice');

        $rows = $this->queued('%APP-FUA-1%');
        $this->assertCount(1, $rows);
        $this->assertSame('Pending', $rows[0]['status']);
        $this->assertSame('Email', $rows[0]['channel']);
        $this->assertSame('staff.one@example.invalid', $rows[0]['recipient_contact']);
        $this->assertStringContainsString('Loan Questions', $rows[0]['message']);
        $this->assertStringNotContainsString("\nInjected", $rows[0]['message'], 'a line break typed into the public form cannot add a line to the e-mail');
    }

    public function testThePopupShowsTheFirstDueQuestionAndNothingWhenNothingIsDue(): void
    {
        $this->application('APP-FUA-2', 'Pop', 'Up');
        $popup = $this->alerts()->popup(date('Y-m-d H:i:s'));
        $this->assertNotNull($popup);
        $this->assertGreaterThanOrEqual(1, $popup['count']);

        $names = array_column(array_column($this->alerts()->cards(date('Y-m-d H:i:s'))['cards'], 'f'), 'application_no');
        $this->assertContains('APP-FUA-2', $names);

        // once it is closed it is no longer asked about
        $this->db->exec("UPDATE loan_followups SET status = 'Closed' WHERE application_no = 'APP-FUA-2'");
        $names = array_column(array_column($this->alerts()->cards(date('Y-m-d H:i:s'))['cards'], 'f'), 'application_no');
        $this->assertNotContains('APP-FUA-2', $names);
    }

    public function testAQuestionThatIsWaitingForALaterDateIsNotInThePopup(): void
    {
        $this->application('APP-FUA-3', 'Later', 'Person');
        $this->alerts()->popup(date('Y-m-d H:i:s')); // creates the row
        $this->db->exec("UPDATE loan_followups SET next_ask_at = DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE application_no = 'APP-FUA-3'");
        $names = array_column(array_column($this->alerts()->cards(date('Y-m-d H:i:s'))['cards'], 'f'), 'application_no');
        $this->assertNotContains('APP-FUA-3', $names);
    }

    public function testTheMorningReminderListsWhatIsWaitingAndIsSentOncePerDay(): void
    {
        $this->application('APP-FUA-4', 'Morning', 'Person');
        $now = date('Y-m-d H:i:s');
        $first = $this->alerts()->dailyReminder($now);
        $this->assertSame(1, $first);
        $this->assertSame(0, $this->alerts()->dailyReminder($now), 'the same person is not sent it twice on the same day');

        $s = $this->db->prepare("SELECT message, subject FROM notification_queue WHERE source_module = 'Loan Questions' AND source_table = 'loan_followups_daily' AND source_id = ?");
        $s->execute([(int) date('Ymd', strtotime($now))]);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringContainsString('APP-FUA-4 (Morning Person)', $row['message']);
        $this->assertStringContainsString('Did you approve this loan?', $row['message']);
        $this->db->exec("DELETE FROM notification_queue WHERE source_module = 'Loan Questions' AND source_table = 'loan_followups_daily' AND message LIKE '%APP-FUA-%' AND source_id = " . (int) date('Ymd', strtotime($now)));
    }

    public function testNothingIsQueuedWhenNobodyIsToldAboutAnythingDue(): void
    {
        $none = new LoanFollowupAlerts(static fn (): array => []);
        $this->assertSame(0, $none->newApplication(900000001, 'APP-FUA-5', 'Nobody Here'));
    }
}
