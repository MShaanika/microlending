<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\StaffSmsAlerts;
use PHPUnit\Framework\TestCase;

/** The staff text-message alerts against the local development database (fixtures use ids >= 900000000, which no real record has). */
class StaffSmsAlertsTest extends TestCase
{
    private \PDO $db;
    /** @var array<int,int> */
    private array $userIds = [];
    private const APP = 900000001;
    private const APP2 = 900000002;
    private const LOAN = 900000003;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->purge();
        $this->userIds = array_map('intval', $this->db->query('SELECT id FROM users ORDER BY id LIMIT 3')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertGreaterThanOrEqual(2, count($this->userIds), 'the development database needs at least two users');
    }

    protected function tearDown(): void
    {
        $this->purge();
    }

    private function purge(): void
    {
        $this->db->exec("DELETE FROM notification_queue WHERE source_module = 'Staff SMS' AND source_id >= 900000000");
    }

    /**
     * @param array<string,string> $settings
     * @param array<int, array{id:int,name:string,phone:string}> $users
     */
    private function alerts(array $settings, ?array $users = null): StaffSmsAlerts
    {
        [$a, $b] = $this->userIds;
        $users ??= [
            ['id' => $a, 'name' => 'Willem Test', 'phone' => '0813000001'],
            ['id' => $b, 'name' => 'Nestor Test', 'phone' => '+264813000002'],
        ];
        return new StaffSmsAlerts(
            static fn (string $key, string $default): string => $settings[$key] ?? $default,
            static fn (array $ids): array => array_values(array_filter($users, static fn (array $u): bool => in_array($u['id'], $ids, true)))
        );
    }

    /** @return array<int, array<string,mixed>> */
    private function queued(string $table, int $sourceId): array
    {
        $s = $this->db->prepare("SELECT * FROM notification_queue WHERE source_module = 'Staff SMS' AND source_table = ? AND source_id = ? ORDER BY user_id");
        $s->execute([$table, $sourceId]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testANewApplicationTextsEveryoneListedOnceWithAnInternationalNumberAndAShortCleanMessage(): void
    {
        [$a, $b] = $this->userIds;
        $alerts = $this->alerts([StaffSmsAlerts::KEY_NEW_APPLICATION_USERS => "$a, $b"]);
        $this->assertSame(2, $alerts->newApplication(self::APP, 'APP-X-1', "Maria\nInjected: line  Test"));
        $this->assertSame(0, $alerts->newApplication(self::APP, 'APP-X-1', 'Maria Test'), 'never twice for the same application');

        $rows = $this->queued('sms_new_application', self::APP);
        $this->assertCount(2, $rows);
        $this->assertSame(['SMS', 'SMS'], array_column($rows, 'channel'));
        $this->assertSame(['Pending', 'Pending'], array_column($rows, 'status'));
        $this->assertSame(['+264813000001', '+264813000002'], array_column($rows, 'recipient_contact'), 'local and international numbers both end up international');
        $this->assertStringNotContainsString("\n", $rows[0]['message']);
        $this->assertStringContainsString('APP-X-1', $rows[0]['message']);
        $this->assertLessThanOrEqual(160, strlen($rows[0]['message']), 'one SMS segment');
    }

    public function testPeopleWithoutAUsablePhoneOrNotListedOrInactiveAreSkipped(): void
    {
        [$a, $b] = $this->userIds;
        $users = [['id' => $a, 'name' => 'No Phone', 'phone' => ''], ['id' => $b, 'name' => 'Has Phone', 'phone' => '0813000009']];
        $this->assertSame(1, $this->alerts([StaffSmsAlerts::KEY_NEW_APPLICATION_USERS => "$a,$b,999999999"], $users)->newApplication(self::APP, 'APP-X-2', 'A B'));
        $this->assertSame(0, $this->alerts([StaffSmsAlerts::KEY_NEW_APPLICATION_USERS => ''])->newApplication(self::APP2, 'APP-X-3', 'A B'), 'an empty list sends nothing (the safe default)');
    }

    public function testTheMasterSwitchStopsEverything(): void
    {
        [$a] = $this->userIds;
        $off = $this->alerts([StaffSmsAlerts::KEY_ENABLED => '0', StaffSmsAlerts::KEY_NEW_APPLICATION_USERS => (string) $a, StaffSmsAlerts::KEY_APPROVED_USERS => (string) $a]);
        $this->assertSame(0, $off->newApplication(self::APP, 'APP-X-4', 'A B'));
        $this->assertSame(0, $off->approved('application', self::APP, null, 'APP-X-4', 'A B', null));
    }

    public function testAnApprovalTextsOnlyTheDisbursementPersonNeverTheApproverAndOnlyOncePerClient(): void
    {
        [$a, $b] = $this->userIds; // $a plays Willem (disburses), $b plays the approver
        $alerts = $this->alerts([StaffSmsAlerts::KEY_APPROVED_USERS => (string) $a]);

        $this->assertSame(1, $alerts->approved('application', self::APP, null, 'APP-X-5', 'Maria Test', $b, 'Raphael'));
        $rows = $this->queued('sms_approved', self::APP);
        $this->assertCount(1, $rows);
        $this->assertSame($a, (int) $rows[0]['user_id']);
        $this->assertStringContainsString('APPROVED', $rows[0]['message']);
        $this->assertStringContainsString('by Raphael', $rows[0]['message']);
        $this->assertStringContainsString('disburse', $rows[0]['message']);

        // the same client approved again at a later step (loan approved, Yes in Loan Questions): still ONE SMS
        $this->assertSame(0, $alerts->approved('loan', self::APP, self::LOAN, 'LN-1', 'Maria Test', $b, 'Raphael'));
        $this->assertSame(0, $alerts->approved('loan_question', self::APP, null, 'APP-X-5', 'Maria Test', $b, 'Absalom'));
        $this->assertCount(1, $this->queued('sms_approved', self::APP));

        // the disbursement person approving their own loan is not told about it
        $this->assertSame(0, $alerts->approved('application', self::APP2, null, 'APP-X-6', 'Other Client', $a, 'Willem'), 'the approver is never texted about their own approval');
    }

    public function testALoanWithNoApplicationIsIdentifiedByTheLoanAndEachStepCanBeSwitchedOff(): void
    {
        [$a, $b] = $this->userIds;
        $on = $this->alerts([StaffSmsAlerts::KEY_APPROVED_USERS => (string) $a]);
        $this->assertSame(1, $on->approved('loan', null, self::LOAN, 'LN-2', 'Walk In', $b));
        $this->assertCount(1, $this->queued('sms_approved_loan', self::LOAN));
        $this->assertSame(0, $on->approved('loan', null, self::LOAN, 'LN-2', 'Walk In', $b), 'not twice');

        $loanOff = $this->alerts([StaffSmsAlerts::KEY_APPROVED_USERS => (string) $a, 'SMS_ALERT_APPROVED_ON_LOAN' => '0']);
        $this->assertSame(0, $loanOff->approved('loan', self::APP2, 900000009, 'LN-3', 'X Y', $b), 'a switched-off step sends nothing');
        $this->assertSame(1, $loanOff->approved('application', self::APP2, null, 'APP-X-7', 'X Y', $b), 'the other steps still work');

        $this->assertSame(0, $on->approved('nonsense', self::APP2, null, 'X', 'X', $b), 'an unknown step sends nothing');
        $this->assertSame(0, $on->approved('loan', null, null, 'X', 'X', $b), 'nothing identifies the client: nothing is sent');
    }

    public function testAFloodOfNewApplicationsStopsAtTheDailyLimitForEachPerson(): void
    {
        [$a] = $this->userIds;
        $alerts = $this->alerts([StaffSmsAlerts::KEY_NEW_APPLICATION_USERS => (string) $a, StaffSmsAlerts::KEY_NEW_APPLICATION_DAILY_LIMIT => '2']);
        $this->assertSame(1, $alerts->newApplication(900000011, 'APP-X-F1', 'A B'));
        $this->assertSame(1, $alerts->newApplication(900000012, 'APP-X-F2', 'A B'));
        $this->assertSame(0, $alerts->newApplication(900000013, 'APP-X-F3', 'A B'), 'the third one today is not texted');
        $this->assertCount(0, $this->queued('sms_new_application', 900000013));
    }

    public function testNothingEverThrowsIntoTheCaller(): void
    {
        $boom = new StaffSmsAlerts(static function (): string { throw new \RuntimeException('settings table is gone'); });
        $this->assertSame(0, $boom->newApplication(self::APP, 'APP-X-8', 'A B'));
        $this->assertSame(0, $boom->approved('application', self::APP, null, 'APP-X-8', 'A B', null));
    }
}
