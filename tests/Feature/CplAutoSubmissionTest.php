<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\CplBatch;
use App\Models\CplSetting;
use App\Models\CreditinfoPublicDefaultSetting;
use App\Services\CplAutoSelector;
use App\Services\CplAutoSubmissionService;
use App\Services\CplSftpService;
use App\Services\LoanFollowupFlow;
use PHPUnit\Framework\TestCase;

/**
 * The automatic daily + monthly CPL submission, end to end against the local dev database -- which on a developer machine holds the REAL Creditinfo
 * SFTP settings. So nothing here reads them or opens a real connection: the CPL and SFTP settings are in-memory fakes (host sftp.test.invalid) and every
 * upload goes through an injected fake connection. Batches use dates in 2018 (nothing real is ever dated then) and are deleted afterwards; the loan ids
 * are fabricated (9,9xx,xxx) and not tied to any real follow-up.
 */
class CplAutoSubmissionTest extends TestCase
{
    private const DAY1 = '2018-10-11';   // a Thursday
    private const DAY2 = '2018-10-12';
    private const DAY5 = '2018-10-15';

    private CplAutoFakeSftp $fake;
    private CplAutoFakeSetting $cpl;
    /** @var array<int, array<string,mixed>> */
    private array $entries = [];
    private string $now = '2018-10-11 06:30:00';
    private int $userId = 0;
    /** @var \PDO[] */
    private array $extraConnections = [];
    /** true = this copy of the system is a demo/sandbox (DemoMode::requiresTestEnvironment) */
    private bool $restricted = false;
    /** null = both scheduled jobs reported an hour ago; otherwise ['daily' => ?datetime, 'monthly' => ?datetime] */
    private ?array $beats = null;
    /** @var array<int, array{id:int,name:string,email:string}>|null null = one test staff member */
    private ?array $staff = null;
    /** a CplBatch (possibly a failing one) to use instead of the real model */
    private ?CplBatch $batches = null;
    /** when set, the daily file the service builds is replaced by this text (to test the final check) */
    private ?string $brokenDailyContent = null;

    protected function setUp(): void
    {
        $this->userId = (int) Database::connection()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId === 0) {
            $this->markTestSkipped('No user in the local database.');
        }
        $this->purge();
        $this->fake = new CplAutoFakeSftp();
        $this->entries = [];
        $this->now = self::DAY1 . ' 06:30:00';
        $this->restricted = false;
        $this->beats = null;
        $this->staff = null;
        $this->batches = null;
        $this->brokenDailyContent = null;
        $this->cpl = new CplAutoFakeSetting([
            'supplier_reference_number' => 'NA02629', 'trading_name' => 'Solid Desert Cash Loan', 'recipient' => 'ALL',
            'submission_environment' => 'production', 'sftp_submission_enabled' => 'on', 'sftp_directory' => '',
            'cpl_auto_submit_enabled' => '1', 'cpl_monthly_send_day' => '3', 'cpl_auto_switch_changed_at' => '2018-10-01 09:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->purge();
        foreach ($this->extraConnections as $c) {
            try {
                $c->query("DO RELEASE_LOCK('" . $this->lockName() . "')");
            } catch (\Throwable $e) {
            }
        }
        $this->extraConnections = [];
    }

    private function purge(): void
    {
        $db = Database::connection();
        $db->exec("DELETE FROM cpl_submissions WHERE batch_id IN (SELECT id FROM cpl_batches WHERE is_uat_test = 0 AND month_end BETWEEN '2016-01-01' AND '2018-12-31')");
        $db->exec("DELETE FROM cpl_batches WHERE is_uat_test = 0 AND month_end BETWEEN '2016-01-01' AND '2018-12-31'");
        $db->exec('DELETE FROM cpl_auto_reported WHERE followup_id >= 9900000');
        $db->exec("DELETE FROM notification_queue WHERE source_module = 'CPL Auto' AND source_id BETWEEN 20180101 AND 20181231");
        try {
            $db->exec("DELETE FROM audit_logs WHERE module_name = 'CPL' AND (description LIKE 'Automatic upload of NA02629\\_ALL\\_L702\\_%' OR description LIKE 'The standing approval of the automatic CPL%' OR description LIKE 'Automatic CPL submission HALTED%' OR description LIKE 'Acknowledged the automatic CPL%' OR description LIKE 'The automatic submission took over the unsent staff batch%')");
        } catch (\Throwable $e) {
            // an append-only audit table is fine too: the rows are only noise
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private function entry(int $n, string $paidOut = '2018-10-10', int $months = 2, int $paid = 0, array $application = []): array
    {
        $fid = 9900000 + $n;
        $schedule = (new LoanFollowupFlow())->schedule(1000.0, $months, $paidOut, 25);
        $instalments = [];
        $payments = [];
        foreach ($schedule as $i => $s) {
            $id = $fid * 100 + $s['instalment_no'];
            $isPaid = $i < $paid;
            $instalments[] = [
                'id' => $id, 'followup_id' => $fid, 'instalment_no' => $s['instalment_no'], 'due_date' => $s['due_date'], 'expected_amount' => $s['expected_amount'],
                'paid_amount' => $isPaid ? $s['expected_amount'] : null, 'paid_on' => $isPaid ? $s['due_date'] : null,
                'answered_at' => $isPaid ? $s['due_date'] . ' 09:00:00' : null, 'next_ask_at' => null,
            ];
            if ($isPaid) {
                $payments[$id] = [['amount' => (string) $s['expected_amount'], 'paid_on' => $s['due_date']]];
            }
        }
        return [
            'followup' => [
                'id' => $fid, 'application_id' => $fid, 'status' => 'Open', 'approved' => 'Yes', 'disbursed' => 'Yes', 'disbursed_on' => $paidOut,
                'approved_amount' => '1000.00', 'term_months' => $months, 'pay_day' => 25,
            ],
            'application' => $application + [
                'id' => $fid, 'application_no' => 'APP-AT' . $n, 'applicant_first_name' => 'Auto' . $n, 'applicant_middle_name' => '', 'applicant_last_name' => 'Tester' . $n,
                'applicant_id_number' => '9001011234' . ($n % 10), 'applicant_gender' => 'Male', 'applicant_phone' => '0811234567',
                'applicant_address' => '1 Test Street Windhoek', 'employer_name' => 'Test Employer', 'gross_salary' => '12000', 'payment_day' => 25, 'requested_term_months' => $months,
            ],
            'instalments' => $instalments,
            'payments' => $payments,
        ];
    }

    private function lockName(): string
    {
        return (new CplAutoSubmissionService($this->cpl))->lockName();
    }

    /**
     * @param array<string,string> $cpl settings to put first
     * @param bool $approve give the standing approval (for the current destination) when the switch is on and none exists yet
     */
    private function service(array $cpl = [], bool $approve = true): CplAutoSubmissionService
    {
        foreach ($cpl as $k => $v) {
            $this->cpl->put($k, $v);
        }
        $batches = $this->batches ?? new CplBatch();
        $public = new CplAutoFakePublicSetting([
            'sftp_enabled' => 'on', 'sftp_host' => 'sftp.test.invalid', 'sftp_port' => '22', 'sftp_username' => 'test-user', 'sftp_outbound_directory' => 'In',
        ], 'test-password');
        $sftp = new CplAutoTestSftp($batches, $this->cpl, $public);
        $sftp->refuse = $this->restricted;
        $svc = new CplAutoTestableService(
            $this->cpl,
            $batches,
            $sftp,
            function (string $host, int $port): CplAutoFakeSftp {
                if ($this->fake->connectThrows !== null) {
                    throw $this->fake->connectThrows;
                }
                return $this->fake;
            },
            fn (): \DateTimeImmutable => new \DateTimeImmutable($this->now),
            fn (): array => $this->entries,
            fn (): array => $this->staff ?? [['id' => $this->userId, 'name' => 'Test Staff', 'email' => 'staff@test.invalid']],
            static fn (\DateTimeImmutable $d): bool => !in_array((int) $d->format('N'), [6, 7], true),
            null,
            fn (): bool => $this->restricted,
            fn (): array => $this->beats ?? ['daily' => date('Y-m-d H:i:s', strtotime($this->now) - 3600), 'monthly' => date('Y-m-d H:i:s', strtotime($this->now) - 3600)]
        );
        $svc->brokenDailyContent = $this->brokenDailyContent;
        if ($approve && $this->cpl->get('cpl_auto_submit_enabled') === '1' && $this->cpl->get('cpl_auto_standing_approval') === '') {
            $this->cpl->put('cpl_auto_standing_approval', json_encode([
                'user_id' => $this->userId, 'at' => '2018-10-01 09:00:00', 'fingerprint' => $svc->currentFingerprint(),
                'preview' => ['daily' => 100, 'monthly' => 100], 'since' => '',
            ]));
        }
        return $svc;
    }

    private function batch(string $type, string $date): ?array
    {
        return (new CplBatch())->findByTypeAndMonth($type, $date, false);
    }

    private function scalarCount(string $sql, array $params = []): int
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function reported(string $kind, ?string $state = 'Reported'): int
    {
        return $this->scalarCount('SELECT COUNT(*) FROM cpl_auto_reported WHERE followup_id >= 9900000 AND report_kind = ?' . ($state ? ' AND state = ?' : ''), $state ? [$kind, $state] : [$kind]);
    }

    private function alerts(string $like = 'cpl_auto_%'): int
    {
        return $this->scalarCount("SELECT COUNT(*) FROM notification_queue WHERE source_module = 'CPL Auto' AND source_table LIKE ? AND source_id BETWEEN 20180101 AND 20181231", [$like]);
    }

    private function assertNothingSent(): void
    {
        $this->assertSame([], $this->fake->puts, 'nothing may be uploaded');
        $this->assertSame([], $this->fake->logins, 'not even a login');
    }

    // --------------------------------------------------------- the safety rails

    public function testSwitchOffSendsNothingButPreparesADraftAndAlertsTheStaff(): void
    {
        $this->entries = [$this->entry(1)];
        $r = $this->service(['cpl_auto_submit_enabled' => '0'])->runDaily();

        $this->assertSame('draft_switch_off', $r['outcome']);
        $this->assertFalse($r['sent']);
        $this->assertNothingSent();
        $b = $this->batch('Daily', self::DAY1);
        $this->assertSame('Ready for Review', $b['status'], 'staff can see exactly what WOULD be sent');
        $this->assertSame(1, (int) $b['total_records']);
        $this->assertStringStartsWith('R', (string) $b['file_content']);
        $this->assertNull($b['generated_by']);
        $this->assertSame(0, $this->reported('R'));
        $this->assertSame(1, $this->reported('R', 'Planned'));
        $this->assertSame(1, $this->alerts('cpl_auto_daily_draft_switch_off'));

        // the same reason on the same day is not e-mailed twice
        $this->service()->runDaily();
        $this->assertSame(1, $this->alerts('cpl_auto_daily_draft_switch_off'));
    }

    public function testTheSwitchDefaultsToOff(): void
    {
        $this->assertFalse((new CplAutoSubmissionService(new CplAutoFakeSetting([])))->isEnabled());
    }

    public function testATestEnvironmentNeverSendsALiveFile(): void
    {
        $this->entries = [$this->entry(1)];
        $r = $this->service(['submission_environment' => 'uat'])->runDaily();
        $this->assertSame('draft_not_production', $r['outcome']);
        $this->assertNothingSent();
        $this->assertSame('Ready for Review', $this->batch('Daily', self::DAY1)['status']);
        $this->assertSame(1, $this->alerts('cpl_auto_daily_draft_not_production'));
    }

    public function testTheLiveSftpSwitchMustAlsoBeOn(): void
    {
        $this->entries = [$this->entry(1)];
        $r = $this->service(['sftp_submission_enabled' => 'off'])->runDaily();
        $this->assertSame('draft_sftp_not_ready', $r['outcome']);
        $this->assertNothingSent();
    }

    public function testValidRecordsGoOutAndOnlyTheExceptedLoanIsHeldBack(): void
    {
        $this->entries = [$this->entry(1), $this->entry(2, '2018-10-10', 2, 0, ['applicant_id_number' => ''])];
        $r = $this->service()->runDaily();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $this->assertSame(1, $r['records']);
        $this->assertSame(1, $r['exceptions']);
        $this->assertCount(1, $this->fake->puts);
        $b = $this->batch('Daily', self::DAY1);
        $this->assertSame('Submitted', $b['status']);
        $this->assertSame(1, (int) $b['total_records']);
        $this->assertSame(1, (int) $b['blocking_error_count']);
        $this->assertSame(1, $this->reported('R'), 'only the valid loan is marked as reported');
        $this->assertSame(1, $this->alerts('cpl_auto_daily_held_loans'));

        // the held-back loan is tried again the next day (and goes out once it is fixed)
        $this->entries = [$this->entry(1), $this->entry(2)];
        $this->now = self::DAY2 . ' 06:30:00';
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $this->assertSame(2, $this->reported('R'));
    }

    public function testAFileWithNothingValidInItIsHeldBackAsAWhole(): void
    {
        $this->entries = [$this->entry(2, '2018-10-10', 2, 0, ['applicant_id_number' => ''])];
        $r = $this->service()->runDaily();
        $this->assertSame('draft_blocked', $r['outcome']);
        $this->assertNothingSent();
        $this->assertSame('Draft', $this->batch('Daily', self::DAY1)['status']);
        $this->assertSame(1, $this->alerts('cpl_auto_daily_draft_blocked'));
        $this->assertSame(0, $this->reported('R'));
    }

    public function testASuccessfulDailyRunSendsALiveFileAndMarksTheLoansReported(): void
    {
        $this->entries = [$this->entry(1), $this->entry(2, '2018-10-09')];
        $r = $this->service()->runDaily();

        $this->assertSame('sent', $r['outcome'], $r['message']);
        $this->assertTrue($r['sent']);
        $name = 'NA02629_ALL_L702_D_20181011_1_1.txt';
        $this->assertSame($name, $r['file']);
        $this->assertSame(['In/' . $name], array_keys($this->fake->puts));
        $content = $this->fake->puts['In/' . $name];
        $lines = explode("\r\n", $content);
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertSame(718, strlen($line));
            $this->assertSame('R', $line[0]);
            $this->assertSame('20181011', substr($line, 710, 8));
        }
        $this->assertStringNotContainsString('T702', $name);

        $b = $this->batch('Daily', self::DAY1);
        $this->assertSame('Submitted', $b['status']);
        $this->assertSame('Awaiting Load Report', $b['submission_outcome'], 'never Accepted: only a person records the load report');
        $this->assertSame(2, $this->reported('R'));
        $sub = Database::connection()->query('SELECT * FROM cpl_submissions WHERE batch_id = ' . (int) $b['id'])->fetch();
        $this->assertSame($name, $sub['file_name']);
        $this->assertNull($sub['submitted_by']);
        $this->assertSame(2, $this->scalarCount("SELECT COUNT(*) FROM cpl_auto_reported WHERE batch_id = ? AND state = 'Reported' AND file_name = ?", [$b['id'], $name]));
    }

    public function testASecondRunTheSameDayMakesNoSecondFile(): void
    {
        $this->entries = [$this->entry(1)];
        $svc = $this->service();
        $this->assertSame('sent', $svc->runDaily()['outcome']);
        $this->entries[] = $this->entry(2); // another loan appears later the same day: it waits for tomorrow's file
        $again = $svc->runDaily();
        $this->assertSame('already_sent', $again['outcome']);
        $this->assertCount(1, $this->fake->puts);
        $this->assertSame(1, $this->scalarCount("SELECT COUNT(*) FROM cpl_batches WHERE batch_type = 'Daily' AND month_end = ? AND is_uat_test = 0", [self::DAY1]));

        $this->now = self::DAY2 . ' 06:30:00';
        $next = $this->service()->runDaily();
        $this->assertSame('sent', $next['outcome']);
        $this->assertCount(2, $this->fake->puts);
        $this->assertSame(2, $this->reported('R'));
    }

    public function testARegistrationIsNeverRepeatedAndNothingIsSentWhenThereIsNothingNew(): void
    {
        $this->entries = [$this->entry(1)];
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $this->now = self::DAY2 . ' 06:30:00';
        $r = $this->service()->runDaily();
        $this->assertSame('nothing_to_report', $r['outcome']);
        $this->assertCount(1, $this->fake->puts, 'an empty daily file is never sent');
        $this->assertNull($this->batch('Daily', self::DAY2), 'and no empty batch is made either');
        $this->assertSame(1, $this->reported('R'));
    }

    public function testCatchUpAfterMissedDaysSendsEverythingStillOwed(): void
    {
        $this->entries = [$this->entry(1, '2018-10-10')];
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);

        // the job did not run for three days; two loans were paid out meanwhile
        $this->entries[] = $this->entry(2, '2018-10-12');
        $this->entries[] = $this->entry(3, '2018-10-13'); // a Saturday
        $this->now = self::DAY5 . ' 06:30:00';
        $r = $this->service()->runDaily();

        $this->assertSame('sent', $r['outcome']);
        $this->assertSame(2, $r['records'], 'both missed loans, and not the one already reported');
        $this->assertSame(3, $this->reported('R'));
        $lines = explode("\r\n", $this->fake->puts['In/NA02629_ALL_L702_D_20181015_1_1.txt']);
        $this->assertCount(2, $lines);
    }

    public function testAClosureIsSentOnceAfterTheRegistration(): void
    {
        $this->entries = [$this->entry(1, '2018-10-10', 2, 0)];
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);

        $this->entries = [$this->entry(1, '2018-10-10', 2, 2)]; // both instalments are paid by now
        $this->now = '2018-12-03 06:30:00';
        $r = $this->service()->runDaily();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $line = $this->fake->puts['In/NA02629_ALL_L702_D_20181203_1_1.txt'];
        $this->assertSame('C', $line[0]);
        $this->assertSame('C', substr($line, 432, 2) === 'C ' ? 'C' : substr($line, 432, 2), 'status code C');
        $this->assertSame(1, $this->reported('R'));
        $this->assertSame(1, $this->reported('C'));

        $this->now = '2018-12-04 06:30:00';
        $this->assertSame('nothing_to_report', $this->service()->runDaily()['outcome'], 'the closure is not repeated');
        $this->assertCount(2, $this->fake->puts);
    }

    public function testAnUnsentDraftIsSupersededByTheNextDaysFile(): void
    {
        $this->entries = [$this->entry(1)];
        $this->service(['cpl_auto_submit_enabled' => '0'])->runDaily();
        $this->now = self::DAY2 . ' 06:30:00';
        $this->service()->runDaily(); // switch is still off -> a second draft
        $this->assertSame('Rejected', $this->batch('Daily', self::DAY1)['status']);
        $this->assertSame('Ready for Review', $this->batch('Daily', self::DAY2)['status']);
        $this->assertSame(1, $this->reported('R', 'Planned'), 'a loan is planned in only one batch');

        $this->service(['cpl_auto_submit_enabled' => '1']);
        $this->now = '2018-10-13 06:30:00';
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $this->assertSame(1, $this->reported('R'));
    }

    // ------------------------------------------------------- failed / uncertain uploads

    public function testAConnectionFailureMarksNothingAndRetriesOnlyAfterTheBackOff(): void
    {
        $this->entries = [$this->entry(1)];
        $this->fake->connectThrows = new \RuntimeException('Could not connect to host');
        $r = $this->service()->runDaily();
        $this->assertSame('failed', $r['outcome']);
        $this->assertSame([], $this->fake->puts);
        $this->assertSame(0, $this->reported('R'));
        $b = $this->batch('Daily', self::DAY1);
        $this->assertSame('Ready for Review', $b['status']);
        $this->assertNull($b['submission_outcome']);
        $this->assertStringStartsWith(CplAutoSubmissionService::FAILED_PREFIX, (string) $b['submission_notes']);
        $this->assertSame(1, $this->alerts('cpl_auto_daily_failed'));
        $this->assertSame('1', $this->cpl->get('cpl_auto_submit_enabled'), 'a plain connection failure does not switch anything off');

        // an hour later: no new attempt
        $this->fake->connectThrows = null;
        $this->now = self::DAY1 . ' 07:30:00';
        $this->assertSame('backoff', $this->service()->runDaily()['outcome']);
        $this->assertSame([], $this->fake->logins);

        // after the back-off the same day's batch is rebuilt and sent
        $this->now = self::DAY1 . ' 13:00:00';
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $this->assertSame(1, $this->reported('R'));
        $this->assertCount(1, $this->fake->puts);
    }

    public function testARefusedLoginIsAGlobalHardStopThatNothingRetries(): void
    {
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->fake->loginOk = false;
        $svc = $this->service();
        $r = $svc->runDaily();
        $this->assertSame('failed', $r['outcome']);
        $this->assertSame([], $this->fake->puts);
        $this->assertCount(1, $this->fake->logins);

        // one global hard stop: switch OFF, approval revoked, halted reason set, ONE alert
        $this->assertSame('0', $this->cpl->get('cpl_auto_submit_enabled'));
        $this->assertSame('', $this->cpl->get('cpl_auto_standing_approval'));
        $this->assertNotNull($svc->haltedReason());
        $this->assertSame(1, $this->alerts('cpl_auto_sftp_login_refused'));

        // neither the daily nor the monthly run tries again, on any later day, even when the switch is turned on by hand in the settings
        $this->fake->loginOk = true;
        $this->cpl->put('cpl_auto_submit_enabled', '1');
        foreach (['2018-10-12 06:30:00', '2018-11-05 07:00:00', '2018-11-20 07:00:00'] as $when) {
            $this->now = $when;
            $this->assertSame('halted', $this->service(['cpl_auto_submit_enabled' => '1'], false)->runDaily()['outcome']);
            if ($when > '2018-11-01') {
                $this->assertSame('halted', $this->service([], false)->runMonthly()['outcome']);
            }
        }
        $this->assertCount(1, $this->fake->logins, 'not one more login was tried');
        $this->assertSame(1, $this->alerts('cpl_auto_sftp_login_refused'), 'alerted once');

        // the switch cannot be turned on again until a person acknowledges, and then it is a fresh approval
        $svc = $this->service([], false);
        try {
            $svc->setEnabled(true, $this->userId, true, null, true, ['daily' => 1, 'monthly' => 1]);
            $this->fail('must refuse while halted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refused', $e->getMessage());
        }
        $this->assertTrue($svc->acknowledgeHalt($this->userId));
        $this->assertNull($svc->haltedReason());
        $this->assertFalse($svc->acknowledgeHalt($this->userId), 'nothing left to acknowledge');
        $this->assertSame('1', $this->cpl->get('cpl_auto_submit_enabled'), 'the test put the switch on by hand above; acknowledging itself does not touch it');
    }

    public function testPutReturningFalseAfterALoginIsUncertainNotFailed(): void
    {
        $this->entries = [$this->entry(1)];
        $this->fake->putOk = false;
        $r = $this->service()->runDaily();
        $this->assertSame('uncertain', $r['outcome']);
        $b = $this->batch('Daily', self::DAY1);
        $this->assertTrue(CplAutoSubmissionService::isUncertain($b));
        $this->assertStringStartsWith(CplAutoSubmissionService::UNCLEAR_PREFIX, (string) $b['submission_notes']);
        $this->assertSame(0, $this->reported('R'));
        $this->assertSame(1, $this->alerts('cpl_auto_daily_uncertain'));
        $this->fake->putOk = true;
        $this->now = self::DAY2 . ' 06:30:00';
        $this->assertSame('uncertain_blocked', $this->service()->runDaily()['outcome']);
        $this->assertSame([], $this->fake->puts);
    }

    public function testAnUncertainUploadIsNotMarkedAlertsAndIsNeverResentOnAGuess(): void
    {
        $this->entries = [$this->entry(1)];
        $this->fake->putThrows = new \RuntimeException('connection timed out');
        $r = $this->service()->runDaily();

        $this->assertSame('uncertain', $r['outcome']);
        $this->assertSame(0, $this->reported('R'), 'not marked reported');
        $this->assertSame(1, $this->reported('R', 'Planned'));
        $b = $this->batch('Daily', self::DAY1);
        $this->assertNotSame('Submitted', $b['status']);
        $this->assertStringStartsWith(CplAutoSubmissionService::UNCLEAR_PREFIX, (string) $b['submission_notes']);
        $this->assertTrue(CplAutoSubmissionService::isUncertain($b));
        $this->assertSame(1, $this->alerts('cpl_auto_daily_uncertain'));

        // the connection is fine again, a new day: still nothing is sent until a person answers
        $this->fake->putThrows = null;
        $this->now = self::DAY2 . ' 06:30:00';
        $held = $this->service()->runDaily();
        $this->assertSame('uncertain_blocked', $held['outcome']);
        $this->assertSame([], $this->fake->puts);
        $this->assertSame(2, $this->alerts('cpl_auto_daily_uncertain'), 'one reminder per day');
        // the monthly file is a different kind: not blocked by the daily one
        $this->assertNotSame('uncertain_blocked', $this->service()->runMonthly(true)['outcome']);

        // a person says Creditinfo does NOT have it: the loan goes out in the next file
        $svc = $this->service();
        $svc->resolveUncertain((int) $b['id'], false, $this->userId);
        $this->assertSame(0, $this->reported('R', 'Planned') + $this->reported('R'));
        $sent = $this->service()->runDaily();
        $this->assertSame('sent', $sent['outcome'], $sent['message']);
        $this->assertSame(1, $this->reported('R'));
        $this->assertCount(1, $this->fake->puts);
    }

    public function testAPersonCanConfirmThatCreditinfoHasTheFile(): void
    {
        $this->entries = [$this->entry(1)];
        $this->fake->putThrows = new \RuntimeException('connection reset');
        $this->service()->runDaily();
        $b = $this->batch('Daily', self::DAY1);

        $this->service()->resolveUncertain((int) $b['id'], true, $this->userId);
        $after = $this->batch('Daily', self::DAY1);
        $this->assertSame('Submitted', $after['status']);
        $this->assertSame(1, $this->reported('R'));
        $this->assertFalse(CplAutoSubmissionService::isUncertain($after));

        $this->fake->putThrows = null;
        $this->now = self::DAY2 . ' 06:30:00';
        $this->assertSame('nothing_to_report', $this->service()->runDaily()['outcome'], 'the loan is not sent again');
        $this->assertSame([], $this->fake->puts);

        $this->expectException(\RuntimeException::class);
        $this->service()->resolveUncertain((int) $b['id'], true, $this->userId); // already answered
    }

    public function testACrashAfterTheUploadLeavesTheBatchUnknownNotUnsent(): void
    {
        $this->entries = [$this->entry(1)];
        $svc = $this->service();
        // simulate: the run died right after "IN PROGRESS" was written (a crash, or the recording write failing after the upload)
        $svc->runDaily(false);
        $b = $this->batch('Daily', self::DAY1);
        (new CplBatch())->updateRecord((int) $b['id'], [
            'status' => 'Ready for Review', 'submission_outcome' => 'Awaiting Load Report',
            'submission_notes' => CplAutoSubmissionService::IN_PROGRESS_PREFIX . ' on 2018-10-11 06:30:00',
        ]);
        Database::connection()->exec("UPDATE cpl_auto_reported SET state = 'Planned' WHERE followup_id >= 9900000");

        $this->fake->puts = [];
        $this->now = self::DAY2 . ' 06:30:00';
        $again = $this->service()->runDaily();
        $this->assertSame('uncertain_blocked', $again['outcome']);
        $this->assertSame([], $this->fake->puts);
    }

    // ------------------------------------------------------------- lock, dry run, naming

    public function testALockHeldByAnotherRunPreventsOverlap(): void
    {
        $this->entries = [$this->entry(1)];
        $c = require ROOT_PATH . '/config/database.php';
        $other = new \PDO("mysql:host={$c['host']};port={$c['port']};dbname=" . (getenv('MLS_DB_NAME') ?: $c['database']) . ";charset={$c['charset']}", $c['username'], $c['password']);
        $this->extraConnections[] = $other;
        $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . $this->lockName() . "', 0)")->fetchColumn());

        $r = $this->service()->runDaily();
        $this->assertSame('locked', $r['outcome']);
        $this->assertNothingSent();
        $this->assertNull($this->batch('Daily', self::DAY1));

        $other->query("DO RELEASE_LOCK('" . $this->lockName() . "')");
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
    }

    public function testADryRunWritesAndSendsNothing(): void
    {
        $this->entries = [$this->entry(1), $this->entry(2, '2018-10-10', 2, 0, ['applicant_id_number' => ''])];
        $before = $this->scalarCount('SELECT COUNT(*) FROM cpl_batches');
        $r = $this->service()->runDaily(true);
        $this->assertTrue($r['dry_run']);
        $this->assertSame(1, $r['records']);
        $this->assertSame(1, $r['exceptions']);
        $this->assertNotEmpty($r['exception_list']);
        $this->assertSame($before, $this->scalarCount('SELECT COUNT(*) FROM cpl_batches'));
        $this->assertSame(0, $this->scalarCount('SELECT COUNT(*) FROM cpl_auto_reported WHERE followup_id >= 9900000'));
        $this->assertSame(0, $this->alerts());
        $this->assertSame('', $this->cpl->get('cpl_auto_last_daily_run'));
        $this->assertNothingSent();
    }

    public function testTheFileNameIsAlwaysLiveNeverTest(): void
    {
        $svc = $this->service(['submission_environment' => 'uat']);
        $this->assertSame('NA02629_ALL_L702_D_20181011_1_1.txt', $svc->liveFilename('D', '2018-10-11'));
        $this->assertSame('NA02629_ALL_L702_M_20180930_1_1.txt', $svc->liveFilename('M', '2018-09-30'));
        $this->assertTrue(CplSftpService::isSafeLiveFilename('NA02629_ALL_L702_D_20181011_1_1.txt'));
        $this->assertFalse(CplSftpService::isSafeLiveFilename('NA02629_ALL_T702_D_20181011_1_1.txt'));
        $this->assertFalse(CplSftpService::isSafeLiveFilename('../NA02629_ALL_L702_D_20181011_1_1.txt'));

        // and the transport itself refuses a test name
        $public = new CplAutoFakePublicSetting(['sftp_enabled' => 'on', 'sftp_host' => 'sftp.test.invalid', 'sftp_username' => 'u', 'sftp_outbound_directory' => 'In'], 'pw');
        $sftp = new CplSftpService(new CplBatch(), $this->cpl, $public);
        try {
            $sftp->uploadLiveFile('NA02629_ALL_T702_D_20181011_1_1.txt', 'x', fn () => $this->fake);
            $this->fail('a T702 name must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('L702', $e->getMessage());
        }
        $this->assertSame([], $this->fake->puts);
    }

    public function testAnAutomaticBatchCannotBeSubmittedByHand(): void
    {
        $this->entries = [$this->entry(1)];
        $this->service(['cpl_auto_submit_enabled' => '0'])->runDaily();
        $b = $this->batch('Daily', self::DAY1);
        (new CplBatch())->updateRecord((int) $b['id'], ['status' => 'Approved']);
        $public = new CplAutoFakePublicSetting(['sftp_enabled' => 'on', 'sftp_host' => 'sftp.test.invalid', 'sftp_username' => 'u', 'sftp_outbound_directory' => 'In'], 'pw');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('automatic submission');
        (new CplSftpService(new CplBatch(), $this->cpl, $public))->submit((int) $b['id'], $this->userId);
    }

    // ---------------------------------------------------------------------- monthly

    public function testTheMonthlyFileWaitsForTheSendDay(): void
    {
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        // 31 Oct is the month end; send day 3 = third working day of November = Monday 5 Nov
        $this->now = '2018-11-02 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('not_due', $r['outcome']);
        $this->assertSame('2018-11-05', $r['send_from']);
        $this->assertNothingSent();
        $this->assertNull($this->batch('Monthly', '2018-10-31'));
    }

    public function testTheMonthlyFileIsSentOnceOnTheSendDay(): void
    {
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1), $this->entry(2, '2018-10-08', 2, 1)];
        $this->now = '2018-11-05 07:00:00';
        $svc = $this->service();
        $r = $svc->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $name = 'NA02629_ALL_L702_M_20181031_1_1.txt';
        $this->assertSame(['In/' . $name], array_keys($this->fake->puts));
        $lines = explode("\r\n", $this->fake->puts['In/' . $name]);
        $this->assertSame('H', $lines[0][0]);
        $this->assertSame('T', $lines[count($lines) - 1][0]);
        $this->assertCount(4, $lines, 'header, 2 loans, trailer');
        $b = $this->batch('Monthly', '2018-10-31');
        $this->assertSame('Submitted', $b['status']);
        $this->assertSame(2, $this->reported('M'));

        // at most once per month end, however often the daily check runs
        $this->now = '2018-11-06 07:00:00';
        $this->assertSame('already_sent', $this->service()->runMonthly()['outcome']);
        $this->assertCount(1, $this->fake->puts);
    }

    public function testAnUnansweredInstalmentHoldsTheMonthlyFileBackUntilItIsAnswered(): void
    {
        // loan 1: instalment 1 (due 25 Oct) is not answered at all
        $this->entries = [$this->entry(1, '2018-10-05', 3, 0), $this->entry(2, '2018-10-08', 2, 1)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('draft_blocked', $r['outcome']);
        $this->assertNothingSent();
        $b = $this->batch('Monthly', '2018-10-31');
        $this->assertSame('Draft', $b['status']);
        $this->assertSame(1, (int) $b['blocking_error_count']);
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_draft_blocked'));

        // staff answer; the next morning's run rebuilds the same batch and sends it
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1), $this->entry(2, '2018-10-08', 2, 1)];
        $this->now = '2018-11-06 07:00:00';
        $sent = $this->service()->runMonthly();
        $this->assertSame('sent', $sent['outcome'], $sent['message']);
        $this->assertSame(1, $this->scalarCount("SELECT COUNT(*) FROM cpl_batches WHERE batch_type = 'Monthly' AND month_end = '2018-10-31' AND is_uat_test = 0"));
        $this->assertCount(1, $this->fake->puts);
    }

    public function testTheMonthlyFileWithSwitchOffIsOnlyADraft(): void
    {
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service(['cpl_auto_submit_enabled' => '0'])->runMonthly();
        $this->assertSame('draft_switch_off', $r['outcome']);
        $this->assertNothingSent();
        $this->assertSame('Ready for Review', $this->batch('Monthly', '2018-10-31')['status']);
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_draft_switch_off'));
    }

    public function testAMonthlyBatchPreparedByStaffIsLeftAlone(): void
    {
        (new CplBatch())->create(['batch_type' => 'Monthly', 'month_end' => '2018-10-31', 'status' => 'Pending Approval']);
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->now = '2018-11-05 07:00:00';
        $this->assertSame('manual_batch', $this->service()->runMonthly()['outcome']);
        $this->assertNothingSent();
    }

    public function testBusinessDayMathSkipsWeekends(): void
    {
        $svc = $this->service();
        $this->assertSame('2018-11-05', $svc->sendFromDate('2018-10-31'));
        $this->assertSame('2018-09-05', $svc->sendFromDate('2018-08-31')); // 3 Sep is a Monday: 3, 4, 5
        $svc2 = $this->service(['cpl_monthly_send_day' => '1']);
        $this->assertSame('2018-11-01', $svc2->sendFromDate('2018-10-31'));
    }

    // ------------------------------------------------------------- switching on

    public function testTurningOnNeedsTheAcknowledgementsAndRecordsTheStandingApproval(): void
    {
        $svc = $this->service(['cpl_auto_submit_enabled' => '0']);
        try {
            $svc->setEnabled(true, $this->userId, false, null, true, ['daily' => 1, 'monthly' => 1]);
            $this->fail('turning on without the acknowledgement must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not the full historic loan book', $e->getMessage());
        }
        try {
            $svc->setEnabled(true, $this->userId, true, null, false, ['daily' => 1, 'monthly' => 1]);
            $this->fail('turning on without the standing approval box must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('standing approval', $e->getMessage());
        }
        $this->assertFalse($svc->isEnabled());
        $this->assertNull($svc->standingApproval());

        $svc->setEnabled(true, $this->userId, true, '2018-10-01', true, ['daily' => 3, 'monthly' => 7]);
        $this->assertTrue($svc->isEnabled());
        $this->assertSame('2018-10-01', $this->cpl->get('cpl_auto_daily_since'));
        $a = $svc->standingApproval();
        $this->assertSame($this->userId, $a['user_id']);
        $this->assertSame(['daily' => 3, 'monthly' => 7], $a['preview']);
        $this->assertSame($svc->currentFingerprint(), $a['fingerprint']);
        $this->assertNotSame('', $this->cpl->get('cpl_auto_enabled_at'));

        $svc->setEnabled(false, $this->userId);
        $this->assertFalse($svc->isEnabled());
        $this->assertNull($svc->standingApproval(), 'turning it off revokes the approval');
        $this->assertNotSame('', $this->cpl->get('cpl_auto_approval_revoked'));
    }

    // ======================================================== maker-checker: the standing approval (item 3)

    public function testWithoutAStandingApprovalNothingIsSent(): void
    {
        $this->entries = [$this->entry(1)];
        $r = $this->service([], false)->runDaily();
        $this->assertSame('draft_no_approval', $r['outcome']);
        $this->assertNothingSent();
        $this->assertSame('Ready for Review', $this->batch('Daily', self::DAY1)['status']);
        $this->assertSame(1, $this->alerts('cpl_auto_daily_draft_no_approval'));
    }

    public function testEveryFileCitesTheStandingApprovalAndNamesTheApprover(): void
    {
        $this->entries = [$this->entry(1)];
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $b = $this->batch('Daily', self::DAY1);
        $this->assertSame($this->userId, (int) $b['approved_by']);
        $this->assertSame('2018-10-01 09:00:00', $b['approved_at']);
        $this->assertStringContainsString('Standing approval given by user #' . $this->userId . ' on 2018-10-01 09:00:00', (string) $b['submission_notes']);
    }

    public function testChangingTheDestinationRevokesTheApprovalAndTheSwitch(): void
    {
        $svc = $this->service();
        $this->assertNotNull($svc->standingApproval());
        $this->assertFalse($svc->revokeIfSettingsChanged($this->userId), 'nothing changed yet');

        $this->cpl->put('recipient', 'OTHER');
        $this->assertTrue($svc->revokeIfSettingsChanged($this->userId));
        $this->assertNull($svc->standingApproval());
        $this->assertSame('0', $this->cpl->get('cpl_auto_submit_enabled'));
        $this->assertNotSame('', $this->cpl->get('cpl_auto_approval_revoked'));

        // every other part of the destination does the same
        foreach ([['submission_environment', 'uat'], ['sftp_submission_enabled', 'off'], ['supplier_reference_number', 'NA09999'], ['sftp_directory', 'Elsewhere']] as [$key, $value]) {
            $this->cpl->put('recipient', 'ALL');
            $this->cpl->put($key, $key === 'sftp_directory' ? '' : ($key === 'supplier_reference_number' ? 'NA02629' : ($key === 'submission_environment' ? 'production' : 'on')));
            $this->cpl->put('cpl_auto_standing_approval', '');
            $this->cpl->put('cpl_auto_submit_enabled', '1');
            $svc = $this->service();
            $this->assertNotNull($svc->standingApproval(), $key);
            $this->cpl->put($key, $value);
            $this->assertTrue($svc->revokeIfSettingsChanged($this->userId), $key);
            $this->assertSame('0', $this->cpl->get('cpl_auto_submit_enabled'), $key);
        }
    }

    public function testAChangedDestinationIsCaughtByTheRunEvenWhenNobodyCalledTheRevokeHook(): void
    {
        $this->entries = [$this->entry(1)];
        $svc = $this->service();
        $this->cpl->put('recipient', 'OTHER'); // e.g. changed straight in the database, or in the SFTP screen of another module
        $r = $svc->runDaily();
        $this->assertSame('draft_no_approval', $r['outcome']);
        $this->assertNothingSent();
        $this->assertSame('0', $this->cpl->get('cpl_auto_submit_enabled'));
        $this->assertNull($svc->standingApproval());
    }

    public function testTheFirstLiveFileMayNotBeMuchBiggerThanThePreviewTheEnablerSaw(): void
    {
        $svc = $this->service([], false);
        $this->cpl->put('cpl_auto_standing_approval', json_encode([
            'user_id' => $this->userId, 'at' => '2018-10-01 09:00:00', 'fingerprint' => $svc->currentFingerprint(), 'preview' => ['daily' => 2, 'monthly' => 2], 'since' => '',
        ]));
        $this->entries = [$this->entry(1), $this->entry(2), $this->entry(3)];   // 3 > 2 * 1.2
        $r = $svc->runDaily();
        $this->assertSame('draft_exceeds_preview', $r['outcome']);
        $this->assertNothingSent();
        $this->assertSame(1, $this->alerts('cpl_auto_daily_draft_exceeds_preview'));

        $this->entries = [$this->entry(1), $this->entry(2)];                    // exactly the preview
        $this->now = self::DAY2 . ' 06:30:00';
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);

        // once a live automatic file of that kind has gone out, the preview no longer limits it
        $this->entries = [$this->entry(1), $this->entry(2), $this->entry(3), $this->entry(4), $this->entry(5), $this->entry(6)];
        $this->now = self::DAY5 . ' 06:30:00';
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
    }

    // ======================================================== instance guard and lock name (item 5)

    public function testADemoOrSandboxCopyNeverSendsAndCannotBeSwitchedOn(): void
    {
        $this->entries = [$this->entry(1)];
        $this->restricted = true;
        $svc = $this->service();
        $this->assertTrue($svc->isRestrictedInstance());
        $r = $svc->runDaily();
        $this->assertSame('refused_instance', $r['outcome']);
        $this->assertNothingSent();
        $this->assertSame(0, $this->alerts(), 'a demo copy e-mails nobody');

        try {
            $this->service(['cpl_auto_submit_enabled' => '0'])->setEnabled(true, $this->userId, true, null, true, ['daily' => 1, 'monthly' => 1]);
            $this->fail('the switch must not turn on there');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('demo or sandbox', $e->getMessage());
        }
        $this->assertNotEmpty($this->service()->enableBlockers($this->userId));

        // and the transport itself refuses, whatever called it
        $public = new CplAutoFakePublicSetting(['sftp_enabled' => 'on', 'sftp_host' => 'sftp.test.invalid', 'sftp_username' => 'u', 'sftp_outbound_directory' => 'In'], 'pw');
        $sftp = new CplAutoTestSftp(new CplBatch(), $this->cpl, $public);
        $sftp->refuse = true;
        try {
            $sftp->uploadLiveFile('NA02629_ALL_L702_D_20181011_1_1.txt', 'x', fn () => $this->fake);
            $this->fail('the transport must refuse on a demo copy');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('demo or sandbox', $e->getMessage());
        }
        $this->assertSame([], $this->fake->logins);
    }

    public function testTheDefaultInstanceGuardIsTheRealDemoMode(): void
    {
        $this->assertSame(\App\Support\DemoMode::requiresTestEnvironment(), (new CplAutoSubmissionService($this->cpl))->isRestrictedInstance());
    }

    public function testTheLockNameIncludesTheDatabaseName(): void
    {
        $db = (string) Database::connection()->query('SELECT DATABASE()')->fetchColumn();
        $name = $this->lockName();
        $this->assertStringContainsString($db, $name);
        $this->assertStringStartsWith(CplAutoSubmissionService::LOCK_NAME, $name);
        $this->assertLessThanOrEqual(64, strlen($name));
    }

    // ======================================================== final file validation (item 7)

    public function testABrokenFileFailsTheFinalCheckAndIsNeverUploaded(): void
    {
        $this->entries = [$this->entry(1)];
        $this->brokenDailyContent = str_repeat('R', 717);   // one byte short
        $r = $this->service()->runDaily();
        $this->assertSame('draft_blocked', $r['outcome']);
        $this->assertStringContainsString('final check', $r['message']);
        $this->assertNothingSent();
        $this->assertSame(1, $this->alerts('cpl_auto_daily_draft_blocked'));
        $this->assertSame(0, $this->reported('R'));

        // a line of the right width in bytes that is not plain ASCII is refused as well
        $this->brokenDailyContent = 'R' . str_repeat(' ', 100) . "\u{E9}" . str_repeat(' ', 607) . '20181011';
        $this->assertSame(718, strlen($this->brokenDailyContent));
        $this->now = self::DAY1 . ' 06:45:00';
        $this->assertSame('draft_blocked', $this->service()->runDaily()['outcome']);
        $this->assertNothingSent();
    }

    public function testAnApplicantWithAccentsStillGivesAnExactWidthAsciiFile(): void
    {
        $this->entries = [$this->entry(1, '2018-10-10', 2, 0, ['applicant_first_name' => "Ren\u{E9}e", 'applicant_last_name' => "M\u{FC}ller-\u{D8}", 'applicant_address' => "12 Stra\u{DF}e \u{201C}Caf\u{E9}\u{201D}"])];
        $r = $this->service()->runDaily();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $line = $this->fake->puts['In/NA02629_ALL_L702_D_20181011_1_1.txt'];
        $this->assertSame(718, strlen($line));
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $line);
        $this->assertStringContainsString('Muller-O', $line);
    }

    // ======================================================== monthly catch-up (item 2)

    /** loan 1: paid out 5 Aug, six instalments on the 25th; $paid of them answered "paid" */
    private function catchUpLoan(int $n, int $paid): array
    {
        return $this->entry($n, '2018-08-05', 6, $paid);
    }

    public function testEveryCompletedMonthEndIsSentOldestFirst(): void
    {
        $this->entries = [$this->catchUpLoan(1, 3)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $this->assertSame([
            'In/NA02629_ALL_L702_M_20180831_1_1.txt',
            'In/NA02629_ALL_L702_M_20180930_1_1.txt',
            'In/NA02629_ALL_L702_M_20181031_1_1.txt',
        ], array_keys($this->fake->puts), 'oldest first');
        foreach (['2018-08-31', '2018-09-30', '2018-10-31'] as $monthEnd) {
            $this->assertSame('Submitted', $this->batch('Monthly', $monthEnd)['status'], $monthEnd);
        }
        $this->assertSame(3, $this->reported('M'));
        $this->assertSame(3, count($r['files']));

        // nothing is sent twice
        $this->now = '2018-11-06 07:00:00';
        $this->assertSame('already_sent', $this->service()->runMonthly()['outcome']);
        $this->assertCount(3, $this->fake->puts);
    }

    public function testTheCatchUpStopsAtTheFirstBlockedMonthSoTheOrderIsKept(): void
    {
        // the Aug 25 instalment is answered, the Sep 25 and Oct 25 ones are not
        $this->entries = [$this->catchUpLoan(1, 1)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('draft_blocked', $r['outcome']);
        $this->assertSame(['In/NA02629_ALL_L702_M_20180831_1_1.txt'], array_keys($this->fake->puts), 'August went; September is blocked and October waits behind it');
        $this->assertSame('Submitted', $this->batch('Monthly', '2018-08-31')['status']);
        $this->assertSame('Draft', $this->batch('Monthly', '2018-09-30')['status']);
        $this->assertNull($this->batch('Monthly', '2018-10-31'), 'October is not even built before September is sent');
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_draft_blocked'));
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_rolled_over'), 'a later month rolled over unsent');

        // the answers arrive: the next run sends September, then October, in that order
        $this->entries = [$this->catchUpLoan(1, 3)];
        $this->now = '2018-11-06 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $this->assertSame([
            'In/NA02629_ALL_L702_M_20180831_1_1.txt',
            'In/NA02629_ALL_L702_M_20180930_1_1.txt',
            'In/NA02629_ALL_L702_M_20181031_1_1.txt',
        ], array_keys($this->fake->puts));
    }

    public function testAMonthThatIsNotDueYetStopsTheRunButEarlierOnesStillGo(): void
    {
        $this->entries = [$this->catchUpLoan(1, 3)];
        $this->now = '2018-11-02 07:00:00';       // October's file is due from 5 Nov; August's and September's are due
        $r = $this->service()->runMonthly();
        $this->assertSame('not_due', $r['outcome']);
        $this->assertSame(['20180831', '20180930'], array_map(static fn (string $f): string => substr($f, strpos($f, '_M_') + 3, 8), array_keys($this->fake->puts)));
    }

    public function testAStaleUnsentStaffBatchIsTakenOverAndRebuiltAsAnAutomaticBatch(): void
    {
        $id = (new CplBatch())->create(['batch_type' => 'Monthly', 'month_end' => '2018-10-31', 'status' => 'Draft']);
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $b = $this->batch('Monthly', '2018-10-31');
        $this->assertSame($id, (int) $b['id'], 'the same batch, rebuilt');
        $this->assertTrue(CplAutoSubmissionService::isAutoBatch($b));
        $this->assertSame('Submitted', $b['status']);
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_taken_over'));
    }

    public function testAStaffBatchThatAlreadyHasASubmissionIsNeverTakenOverAndTheStaffAreAlerted(): void
    {
        $id = (new CplBatch())->create(['batch_type' => 'Monthly', 'month_end' => '2018-10-31', 'status' => 'Draft']);
        Database::connection()->prepare("INSERT INTO cpl_submissions (batch_id, sequence_number, file_name, submitted_at, outcome) VALUES (?, 1, 'x.txt', '2018-11-01 10:00:00', 'Awaiting Load Report')")->execute([$id]);
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->now = '2018-11-05 07:00:00';
        $this->assertSame('manual_batch', $this->service()->runMonthly()['outcome']);
        $this->assertNothingSent();
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_manual_batch'));
        $this->assertFalse(CplAutoSubmissionService::isAutoBatch($this->batch('Monthly', '2018-10-31')));
    }

    public function testAManualBatchInTheWayBlocksLaterMonthsAndAlertsThatTheyRolledOver(): void
    {
        (new CplBatch())->create(['batch_type' => 'Monthly', 'month_end' => '2018-09-30', 'status' => 'Pending Approval']);
        $this->entries = [$this->catchUpLoan(1, 3)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('manual_batch', $r['outcome']);
        $this->assertSame(['In/NA02629_ALL_L702_M_20180831_1_1.txt'], array_keys($this->fake->puts), 'only August, which is before the blocked month');
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_manual_batch'));
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_rolled_over'));
        $this->assertNull($this->batch('Monthly', '2018-10-31'));
    }

    // ------------------------------------------------ re-review round: takeover, order, window, link, approver, trading name, preview

    private function rawBatch(array $data): int
    {
        return (new CplBatch())->create($data + ['batch_type' => 'Monthly', 'month_end' => '2018-10-31', 'status' => 'Draft']);
    }

    public function testAFullBookBatchFromTheOldSystemIsNeverRebuiltOrSent(): void
    {
        $id = $this->rawBatch(['source' => 'SolidFin', 'legacy_summary' => json_encode(['origin' => 'Extracted from the old loan system', 'sent' => 941]), 'file_content' => 'FULL BOOK', 'total_records' => 941]);
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('manual_batch', $r['outcome']);
        $this->assertNothingSent();
        $b = $this->batch('Monthly', '2018-10-31');
        $this->assertSame($id, (int) $b['id']);
        $this->assertFalse(CplAutoSubmissionService::isAutoBatch($b), 'still the staff batch');
        $this->assertSame('FULL BOOK', $b['file_content'], 'never rebuilt');
        $this->assertSame('Draft', $b['status']);
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_manual_batch'));
        $this->assertSame(0, $this->alerts('cpl_auto_monthly_taken_over'));
        $this->assertSame(0, $this->reported('M', null));
    }

    public function testAnApprovedStaffBatchIsNeverTakenOver(): void
    {
        $this->rawBatch(['approved_by' => $this->userId, 'approved_at' => '2018-11-01 09:00:00']);
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->now = '2018-11-05 07:00:00';
        $this->assertSame('manual_batch', $this->service()->runMonthly()['outcome']);
        $this->assertNothingSent();
    }

    public function testALoanQuestionsBatchFromTheApplicationsScriptIsTakenOver(): void
    {
        $id = $this->rawBatch(['source' => 'SolidFin', 'legacy_summary' => json_encode(['origin' => 'Built from the approved online applications'])]);
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1)];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $b = $this->batch('Monthly', '2018-10-31');
        $this->assertSame($id, (int) $b['id']);
        $this->assertTrue(CplAutoSubmissionService::isAutoBatch($b));
    }

    public function testAnOlderMonthIsNeverSentAfterALaterAutomaticMonthWasSubmitted(): void
    {
        // October was already sent by the automatic run; August and September of the same loan are still unsent
        $this->rawBatch(['source' => 'SolidFin', 'status' => 'Submitted', 'month_end' => '2018-10-31', 'submission_outcome' => 'Awaiting Load Report', 'legacy_summary' => json_encode(['origin' => CplAutoSubmissionService::ORIGIN, 'kind' => 'Monthly'])]);
        $this->entries = [$this->catchUpLoan(1, 3)];
        $this->now = '2018-11-05 07:00:00';

        $dry = $this->service()->runMonthly(true);
        $this->assertSame('draft_out_of_order', $dry['outcome']);
        $this->assertFalse($dry['would_send']);

        $r = $this->service()->runMonthly();
        $this->assertSame('draft_out_of_order', $r['outcome'], $r['message']);
        $this->assertStringContainsString('2018-10-31', $r['message']);
        $this->assertNothingSent();
        $aug = $this->batch('Monthly', '2018-08-31');
        $this->assertNotNull($aug, 'held as a draft for a person to decide');
        $this->assertContains($aug['status'], ['Draft', 'Ready for Review']);
        $this->assertNull($this->batch('Monthly', '2018-09-30'), 'later months wait behind it');
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_draft_out_of_order'));
        $this->assertSame(0, $this->reported('M', 'Reported'));

        // the same day again: still nothing sent, still one alert
        $this->service()->runMonthly();
        $this->assertNothingSent();
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_draft_out_of_order'));
    }

    public function testAStaffSubmissionOfALaterMonthDoesNotBlockTheAutomaticOrder(): void
    {
        // only AUTOMATIC later months count (a staff batch is a person's own decision)
        $this->rawBatch(['status' => 'Submitted', 'month_end' => '2018-10-31', 'submission_outcome' => 'Awaiting Load Report']);
        $this->entries = [$this->catchUpLoan(1, 3)];
        $this->now = '2018-11-05 07:00:00';
        $this->assertNotSame('draft_out_of_order', $this->service()->runMonthly(true)['outcome']);
    }

    public function testTheMonthWindowSkipsSubmittedMonthsBeforeTheCapSoTheLatestMonthIsNeverCutOff(): void
    {
        // 35 completed month ends (2016-01 .. 2018-11): more than the 24 handled per run
        $this->entries = [$this->entry(1, '2016-01-05', 60, 60)];
        $this->now = '2018-12-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $this->assertCount(CplAutoSubmissionService::MAX_MONTHS_PER_RUN, $this->fake->puts);
        $this->assertSame('Submitted', $this->batch('Monthly', '2017-12-31')['status']);
        $this->assertNull($this->batch('Monthly', '2018-01-31'));
        $this->assertStringContainsString('wait for the next run', $r['message']);
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_window_capped'), 'the staff are told the latest months are not in this run');

        // the next run skips the 24 Submitted months FIRST, then reaches the latest one
        $this->now = '2018-12-06 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $this->assertCount(35, $this->fake->puts);
        $this->assertSame('Submitted', $this->batch('Monthly', '2018-11-30')['status']);
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_window_capped'), 'no second capped alert: the window now ends at the latest month');
    }

    public function testAlertLinksAreAbsoluteAndNeverBuiltFromTheCronScript(): void
    {
        $saved = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/var/www/bin/cpl_auto_submit.php';
        try {
            $svc = $this->service();
            $this->assertMatchesRegularExpression('~^https?://~', $svc->link('/reports/cpl-export/batches'));

            $this->cpl->put('cpl_public_base_url', 'https://app.example.test/mls/');
            $this->assertSame('https://app.example.test/mls/reports/cpl-export/batches/5', $this->service()->link('/reports/cpl-export/batches/5'));

            // a junk setting is ignored: the configured application address is used instead, never the request
            $this->cpl->put('cpl_public_base_url', 'javascript:alert(1)');
            $this->assertMatchesRegularExpression('~^https?://[^/]+~', $this->service()->link('/x'));

            // and a real alert e-mail carries such a link
            $this->cpl->put('cpl_public_base_url', 'https://app.example.test/mls');
            $this->entries = [$this->entry(1)];
            $this->service(['cpl_auto_submit_enabled' => '0'])->runDaily();
            $stmt = Database::connection()->prepare("SELECT message FROM notification_queue WHERE source_module = 'CPL Auto' AND source_table = 'cpl_auto_daily_draft_switch_off' AND source_id BETWEEN 20180101 AND 20181231 ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $message = (string) $stmt->fetchColumn();
            $this->assertMatchesRegularExpression('~https://app\.example\.test/mls/reports/cpl-export/batches/\d+~', $message);
            $this->assertStringNotContainsString('cpl_auto_submit.php', $message);
        } finally {
            if ($saved === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $saved;
            }
        }
    }

    private function approvalBy(CplAutoSubmissionService $svc, int $userId): string
    {
        return (string) json_encode(['user_id' => $userId, 'at' => '2018-10-01 09:00:00', 'fingerprint' => $svc->currentFingerprint(), 'preview' => ['daily' => 100, 'monthly' => 100], 'since' => '']);
    }

    public function testAnApprovalWhoseApproverIsGoneInactiveOrNoLongerAllowedIsRevokedAndNothingIsSent(): void
    {
        $db = Database::connection();
        $holders = array_map('intval', array_column((new \App\Models\User())->usersWithPermission('reports.cpl_approve'), 'id'));
        $cases = ['no longer exists' => 2147000000];
        $inactive = $db->query('SELECT id FROM users WHERE is_active = 0 ORDER BY id LIMIT 1')->fetchColumn();
        if ($inactive) {
            $cases['no longer an active user'] = (int) $inactive;
        }
        $noPermission = null;
        foreach ($db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
            if (!in_array((int) $uid, $holders, true)) {
                $noPermission = (int) $uid;
                break;
            }
        }
        if ($noPermission !== null) {
            $cases['no longer holds the CPL approval permission'] = $noPermission;
        }

        foreach ($cases as $cause => $uid) {
            $this->purge();
            $this->fake = new CplAutoFakeSftp();
            $this->entries = [$this->entry(1)];
            $svc = $this->service([], false);
            $this->cpl->put('cpl_auto_submit_enabled', '1');
            $this->cpl->put('cpl_auto_standing_approval', $this->approvalBy($svc, $uid));
            $r = $svc->runDaily();
            $this->assertSame('draft_no_approval', $r['outcome'], $cause);
            $this->assertNothingSent();
            $this->assertNull($svc->standingApproval(), $cause);
            $this->assertSame('0', $this->cpl->get('cpl_auto_submit_enabled'), $cause);
            $this->assertStringContainsString($cause, $this->cpl->get('cpl_auto_approval_revoked'), 'the cause is in the revocation reason');
        }

        // a holder of the permission who is active is still fine
        $this->purge();
        $this->fake = new CplAutoFakeSftp();
        $this->entries = [$this->entry(1)];
        $this->cpl->put('cpl_auto_standing_approval', '');
        $this->cpl->put('cpl_auto_submit_enabled', '1');
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
    }

    public function testChangingTheTradingNameRevokesTheApprovalAndTheSwitch(): void
    {
        $svc = $this->service();
        $this->assertNotNull($svc->standingApproval());
        $this->cpl->put('trading_name', 'Another Trading Name');
        $this->assertTrue($svc->revokeIfSettingsChanged($this->userId));
        $this->assertNull($svc->standingApproval());
        $this->assertSame('0', $this->cpl->get('cpl_auto_submit_enabled'));
    }

    public function testThePreviewForTheApprovalIsMeasuredOnTheServerAndAStalePageIsDetected(): void
    {
        $this->entries = [$this->entry(1), $this->entry(2), $this->entry(3)];
        $svc = $this->service([], false);
        $m = $svc->firstFilePreview(null);
        $this->assertGreaterThan(0, $m['daily_records']);

        $same = $svc->previewForEnable(null, ['daily' => $m['daily_records'], 'monthly' => $m['monthly_records']]);
        $this->assertFalse($same['stale']);
        $this->assertSame(['daily' => $m['daily_records'], 'monthly' => $m['monthly_records']], $same['preview']);

        // a forged or old page claiming a tiny preview cannot lower what is stored: the measured numbers come back, and the page is flagged stale
        $forged = $svc->previewForEnable(null, ['daily' => 1, 'monthly' => 0]);
        $this->assertTrue($forged['stale']);
        $this->assertSame($m['daily_records'], $forged['preview']['daily']);
        $this->assertTrue($svc->previewForEnable(null, null)['stale']);
    }

    public function testTheNextMonthlyFileInThePanelFollowsTheFirstOfTheMonth(): void
    {
        $this->now = '2018-03-31 07:00:00';          // a 31st: the latest COMPLETED month end is 28 Feb, not "31 March - 1 month"
        $panel = $this->service()->panel();
        $this->assertSame('2018-02-28', $panel['next_monthly_month_end']);
        $this->assertSame('2018-03-05', $panel['next_monthly_due']);
        $this->now = '2018-05-31 07:00:00';
        $this->assertSame('2018-04-30', $this->service()->panel()['next_monthly_month_end']);
        $this->assertStringNotContainsString('06:30', $panel['next_daily']);
    }

    public function testAKnownNoAnswerOlderThanTheReAskWindowDoesNotHoldTheMonthlyFileBack(): void
    {
        $e = $this->entry(1, '2018-10-05', 3, 1);
        // instalment 2 (due 25 Oct) was answered "not paid" on 26 Oct; the re-ask time (2 Nov) has passed by the 5th
        $e['instalments'][1]['paid_amount'] = '0.00';
        $e['instalments'][1]['answered_at'] = '2018-10-26 09:00:00';
        $e['instalments'][1]['next_ask_at'] = '2018-11-02 09:00:00';
        $this->entries = [$e];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $this->assertCount(1, $this->fake->puts);

        // an instalment nobody ever answered still holds the file
        $this->purge();
        $this->fake = new CplAutoFakeSftp();
        $e2 = $this->entry(2, '2018-10-05', 3, 0);
        $this->entries = [$this->entry(3, '2018-10-05', 3, 1), $e2];
        $this->assertSame('draft_blocked', $this->service()->runMonthly()['outcome']);
        $this->assertNothingSent();
    }

    public function testAMonthlyFileLeavesOutALoanThatFailsValidationButSendsTheRest(): void
    {
        $this->entries = [$this->entry(1, '2018-10-05', 3, 1), $this->entry(2, '2018-10-05', 3, 1, ['applicant_id_number' => ''])];
        $this->now = '2018-11-05 07:00:00';
        $r = $this->service()->runMonthly();
        $this->assertSame('sent', $r['outcome'], $r['message']);
        $lines = explode("\r\n", $this->fake->puts['In/NA02629_ALL_L702_M_20181031_1_1.txt']);
        $this->assertCount(3, $lines, 'header, the one valid loan, trailer');
        $this->assertSame(1, $this->reported('M'));
        $this->assertSame(1, $this->alerts('cpl_auto_monthly_held_loans'));
    }

    // ======================================================== cron health (item 9)

    public function testAMissingOrOldHeartbeatIsAProblemOnlyWhileTheSwitchIsOn(): void
    {
        $this->beats = ['daily' => null, 'monthly' => null];
        $h = $this->service()->cronHealth();
        $this->assertTrue($h['problem']);
        $this->assertTrue($h['any_missing']);
        $this->assertFalse($this->service(['cpl_auto_submit_enabled' => '0'])->cronHealth()['problem'], 'nothing to warn about while it is off');

        $this->cpl->put('cpl_auto_submit_enabled', '1');
        $this->beats = ['daily' => '2018-10-09 22:00:00', 'monthly' => '2018-10-11 05:00:00'];      // 32.5 hours ago and 1.5 hours ago
        $h = $this->service()->cronHealth();
        $this->assertTrue($h['problem']);
        $this->assertFalse($h['any_missing']);
        $this->assertTrue($h['daily']['stale']);
        $this->assertFalse($h['monthly']['stale']);
        $this->beats = ['daily' => '2018-10-10 12:00:00', 'monthly' => '2018-10-11 05:00:00'];
        $this->assertFalse($this->service()->cronHealth()['problem']);
    }

    public function testTheStaffAreEmailedOncePerDayWhenAJobStopsReporting(): void
    {
        $this->cpl->put('cpl_auto_enabled_at', '2018-09-01 09:00:00');
        $this->beats = ['daily' => '2018-10-08 06:30:00', 'monthly' => '2018-10-08 07:00:00'];
        $svc = $this->service();
        $this->assertTrue($svc->watchCron());
        $this->assertTrue($svc->watchCron());
        $this->assertSame(1, $this->alerts('cpl_auto_cron_heartbeat_stale'), 'once a day');
        $this->now = self::DAY2 . ' 08:00:00';
        $this->assertTrue($this->service()->watchCron());
        $this->assertSame(2, $this->alerts('cpl_auto_cron_heartbeat_stale'));

        $this->beats = null;
        $this->assertFalse($this->service()->watchCron(), 'all well: no alert');
        $this->assertSame(2, $this->alerts('cpl_auto_cron_heartbeat_stale'));
    }

    public function testASwitchTurnedOnJustNowIsNotReportedForJobsThatHaveNotHadTimeToRun(): void
    {
        $this->beats = ['daily' => null, 'monthly' => null];
        $this->cpl->put('cpl_auto_enabled_at', '2018-10-11 05:00:00');
        $this->assertTrue($this->service()->watchCron());
        $this->assertSame(0, $this->alerts('cpl_auto_cron_heartbeat_stale'));
    }

    public function testTurningOnWithNoHeartbeatYetNeedsTheExtraAcknowledgement(): void
    {
        $this->beats = ['daily' => null, 'monthly' => null];
        $svc = $this->service(['cpl_auto_submit_enabled' => '0']);
        try {
            $svc->setEnabled(true, $this->userId, true, null, true, ['daily' => 1, 'monthly' => 1]);
            $this->fail('the extra box is required');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('never reported', $e->getMessage());
        }
        $this->assertFalse($svc->isEnabled());
        $svc->setEnabled(true, $this->userId, true, null, true, ['daily' => 1, 'monthly' => 1], true);
        $this->assertTrue($svc->isEnabled());
    }

    // ======================================================== alerts and privacy (item 10)

    public function testNobodyToAlertIsLoggedAndTheSwitchCannotBeTurnedOn(): void
    {
        $this->staff = [];
        $log = tempnam(sys_get_temp_dir(), 'cplalert');
        $old = ini_set('error_log', $log);
        try {
            $this->entries = [$this->entry(1)];
            $this->service(['cpl_auto_submit_enabled' => '0'])->runDaily();     // a draft + an alert that has nobody to go to
            $svc = $this->service(['cpl_auto_submit_enabled' => '0']);
            $this->assertSame([], $svc->recipientList());
            $this->assertNotEmpty($svc->enableBlockers(null));
            try {
                $svc->setEnabled(true, null, true, null, true, ['daily' => 1, 'monthly' => 1]);
                $this->fail('with nobody to alert the switch must stay off');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Nobody with an e-mail address', $e->getMessage());
            }
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
        }
        $logged = (string) file_get_contents($log);
        @unlink($log);
        $this->assertStringContainsString('no one with an e-mail address', $logged);
        $this->assertStringNotContainsString('Tester', $logged);
        $this->assertSame(0, $this->alerts());
    }

    public function testTheEnablingUserIsTheFallbackRecipient(): void
    {
        $id = (int) Database::connection()->query("SELECT id FROM users WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY id LIMIT 1")->fetchColumn();
        if ($id === 0) {
            $this->markTestSkipped('No active user with an e-mail address in the local database.');
        }
        $this->staff = [];
        $svc = $this->service(['cpl_auto_submit_enabled' => '0']);
        $this->assertSame([], $svc->enableBlockers($id));
        $svc->setEnabled(true, $id, true, null, true, ['daily' => 1, 'monthly' => 1]);
        $this->assertStringContainsString((string) $id, $this->cpl->get('cpl_auto_alert_user_ids'));
    }

    public function testNoPersonalDataEverReachesAnEmailOrTheLog(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'cplpriv');
        $old = ini_set('error_log', $log);
        try {
            // a validation exception, a draft with the switch off, a failed upload, an uncertain upload and a blocked month
            $this->entries = [$this->entry(1), $this->entry(2, '2018-10-10', 2, 0, ['applicant_id_number' => ''])];
            $this->service(['cpl_auto_submit_enabled' => '0'])->runDaily();
            $this->fake->connectThrows = new \RuntimeException('Could not connect to host 10.1.2.3 as Tester1 pw secret1234567');
            $this->service(['cpl_auto_submit_enabled' => '1'])->runDaily();
            $this->fake->connectThrows = null;
            $this->fake->putThrows = new \RuntimeException('timeout for Auto1 Tester1 APP-AT1');
            $this->now = '2018-10-12 06:30:00';
            $this->service()->runDaily();
            $this->entries = [$this->entry(1, '2018-10-05', 3, 0)];
            $this->now = '2018-11-05 07:00:00';
            $this->service()->runMonthly();
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
        }
        $logged = (string) file_get_contents($log);
        @unlink($log);
        $rows = Database::connection()->query("SELECT subject, message FROM notification_queue WHERE source_module = 'CPL Auto' AND source_id BETWEEN 20180101 AND 20181231")->fetchAll();
        $this->assertNotEmpty($rows);
        $text = $logged;
        foreach ($rows as $row) {
            $text .= ' ' . $row['subject'] . ' ' . $row['message'];
        }
        foreach (['Tester', 'Auto1', 'Auto2', 'APP-AT', '10.1.2.3', 'secret1234567', '90010112340', 'timeout for'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, 'no personal data or raw exception text: ' . $needle);
        }
        $this->assertStringContainsString('/reports/cpl-export', $text, 'a dashboard link is there');
    }

    public function testEveryUploadAttemptAndOutcomeIsAudited(): void
    {
        $this->entries = [$this->entry(1)];
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $stmt = Database::connection()->query("SELECT description, metadata FROM audit_logs WHERE module_name = 'CPL' AND description LIKE 'Automatic upload of NA02629%20181011%' ORDER BY id");
        $rows = $stmt->fetchAll();
        $this->assertCount(2, $rows, 'started + sent');
        $outcomes = array_map(static fn (array $r): string => (string) (json_decode((string) $r['metadata'], true)['outcome'] ?? ''), $rows);
        $this->assertSame(['started', 'sent'], $outcomes);
        $meta = json_decode((string) $rows[1]['metadata'], true);
        $this->assertSame('NA02629_ALL_L702_D_20181011_1_1.txt', $meta['file']);
        $this->assertSame(1, $meta['records']);
        $this->assertArrayHasKey('batch_id', $meta);
        $this->assertStringNotContainsString('Tester', (string) $rows[0]['description'] . (string) $rows[1]['description'] . (string) $rows[1]['metadata']);
    }

    // ======================================================== resolving an uncertain upload (item 11)

    private function inProgressBatch(): int
    {
        $this->entries = [$this->entry(1)];
        $this->service()->runDaily(false);
        $b = $this->batch('Daily', self::DAY1);
        // simulate a run that died right after "IN PROGRESS" was written
        (new CplBatch())->updateRecord((int) $b['id'], [
            'status' => 'Ready for Review', 'submission_outcome' => 'Awaiting Load Report',
            'submission_notes' => CplAutoSubmissionService::IN_PROGRESS_PREFIX . ' on 2018-10-11 06:30:00', 'submission_recorded_at' => '2018-10-11 06:30:00',
        ]);
        Database::connection()->exec("UPDATE cpl_auto_reported SET state = 'Planned' WHERE followup_id >= 9900000");
        $this->fake->puts = [];
        return (int) $b['id'];
    }

    public function testAnAnswerIsRefusedWhileARunMayBeInTheMiddleOfThatUploadButAllowedOnceTheNoteIsOld(): void
    {
        $this->entries = [$this->entry(1)];
        $id = $this->inProgressBatch();
        $c = require ROOT_PATH . '/config/database.php';
        $other = new \PDO("mysql:host={$c['host']};port={$c['port']};dbname=" . (getenv('MLS_DB_NAME') ?: $c['database']) . ";charset={$c['charset']}", $c['username'], $c['password']);
        $this->extraConnections[] = $other;
        $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . $this->lockName() . "', 0)")->fetchColumn());

        $this->now = '2018-10-11 06:35:00';       // five minutes after the note: that run may still be uploading
        try {
            $this->service()->resolveUncertain($id, true, $this->userId);
            $this->fail('must be refused while a run holds the lock and the note is fresh');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('busy', $e->getMessage());
        }
        $this->assertTrue(CplAutoSubmissionService::isUncertain((new CplBatch())->find($id)));

        $this->now = '2018-10-11 07:00:00';       // half an hour later: that run is dead
        $this->service()->resolveUncertain($id, true, $this->userId);
        $this->assertSame('Submitted', (new CplBatch())->find($id)['status']);
    }

    public function testAnAnswerForABatchThatIsAlreadySubmittedSaysSo(): void
    {
        $this->entries = [$this->entry(1)];
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $b = $this->batch('Daily', self::DAY1);
        try {
            $this->service()->resolveUncertain((int) $b['id'], true, $this->userId);
            $this->fail('nothing to confirm');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already recorded as Submitted', $e->getMessage());
        }
    }

    public function testConfirmingAFileWhoseLoansAreGoneChangesNothing(): void
    {
        $id = $this->inProgressBatch();
        Database::connection()->exec("DELETE FROM cpl_auto_reported WHERE followup_id >= 9900000");
        $this->now = '2018-10-11 07:00:00';
        try {
            $this->service()->resolveUncertain($id, true, $this->userId);
            $this->fail('no loan row can be marked, so it must fail loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nothing was changed', $e->getMessage());
        }
        $b = (new CplBatch())->find($id);
        $this->assertNotSame('Submitted', $b['status']);
        $this->assertTrue(CplAutoSubmissionService::isUncertain($b));
    }

    public function testARealWriteFailureAfterTheUploadLeavesTheBatchUnknownAndNothingMarked(): void
    {
        $this->entries = [$this->entry(1)];
        $failing = new CplAutoFailingBatch();
        $failing->failSubmitted = true;
        $this->batches = $failing;
        $r = $this->service()->runDaily();
        $this->assertSame('uncertain', $r['outcome']);
        $this->assertCount(1, $this->fake->puts, 'the file really was uploaded');
        $b = $this->batch('Daily', self::DAY1);
        $this->assertNotSame('Submitted', $b['status']);
        $this->assertTrue(CplAutoSubmissionService::isUncertain($b), 'held as outcome-unknown, never as unsent');
        $this->assertStringStartsWith(CplAutoSubmissionService::IN_PROGRESS_PREFIX, (string) $b['submission_notes']);
        $this->assertSame(0, $this->reported('R'), 'the transaction was rolled back: nothing is marked reported');
        $this->assertSame(1, $this->reported('R', 'Planned'));
        $this->assertSame(1, $this->alerts('cpl_auto_daily_unrecorded'));
        $this->assertSame(0, $this->scalarCount('SELECT COUNT(*) FROM cpl_submissions WHERE batch_id = ?', [$b['id']]));

        // the next run does not send it again
        $failing->failSubmitted = false;
        $this->fake->puts = [];
        $this->now = self::DAY2 . ' 06:30:00';
        $this->assertSame('uncertain_blocked', $this->service()->runDaily()['outcome']);
        $this->assertSame([], $this->fake->puts);
    }

    public function testNoRowMarkedAfterTheUploadFailsLoudly(): void
    {
        $this->entries = [$this->entry(1)];
        // the loan rows vanish between the upload and the recording
        $this->fake->onPut = static function (): void {
            Database::connection()->exec("DELETE FROM cpl_auto_reported WHERE followup_id >= 9900000");
        };
        $r = $this->service()->runDaily();
        $this->assertSame('uncertain', $r['outcome']);
        $b = $this->batch('Daily', self::DAY1);
        $this->assertNotSame('Submitted', $b['status']);
        $this->assertTrue(CplAutoSubmissionService::isUncertain($b));
        $this->assertSame(1, $this->alerts('cpl_auto_daily_unrecorded'));
    }

    public function testAFailedBookkeepingWriteAfterTheRecordedUploadDoesNotUndoIt(): void
    {
        $this->entries = [$this->entry(1)];
        $this->cpl->failKeys = ['cpl_auto_last_success'];
        $r = $this->service()->runDaily();
        $this->assertSame('sent', $r['outcome']);
        $this->assertSame('Submitted', $this->batch('Daily', self::DAY1)['status']);
        $this->assertSame(1, $this->reported('R'));
    }

    // ======================================================== dashboard lists (item 13)

    public function testDailyAutomaticFilesDoNotFloodRecentBatchesAndHaveTheirOwnPagedList(): void
    {
        $this->entries = [$this->entry(1, '2018-10-01')];
        foreach (['2018-10-11', '2018-10-12'] as $day) {
            $this->now = $day . ' 06:30:00';
            $this->entries[] = $this->entry((int) substr($day, -2), $day);
            $this->service()->runDaily();
        }
        $manual = (new CplBatch())->create(['batch_type' => 'Daily', 'month_end' => '2018-10-20', 'status' => 'Draft']);
        $batches = new CplBatch();
        $recent = array_column($batches->recentExcludingAutoDaily(200), 'id');
        $this->assertContains($manual, array_map('intval', $recent), 'a manual daily batch is still listed');
        foreach (['2018-10-11', '2018-10-12'] as $day) {
            $this->assertNotContains((int) $this->batch('Daily', $day)['id'], array_map('intval', $recent), 'the automatic daily file of ' . $day);
        }
        $this->assertGreaterThanOrEqual(2, $batches->countAutoFiles());
        $this->assertCount(1, $batches->autoFiles(1, 0));
        $panel = $this->service()->panel(1);
        $this->assertSame($batches->countAutoFiles(), $panel['auto_total']);
        $this->assertLessThanOrEqual(30, count($panel['auto_files']));
        $this->assertGreaterThanOrEqual(1, $panel['auto_pages']);
    }

    // ======================================================== closure records (item 1) end to end

    public function testTheClosureInTheDailyFileCarriesAZeroInstalment(): void
    {
        $this->entries = [$this->entry(1, '2018-10-10', 2, 0)];
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $this->entries = [$this->entry(1, '2018-10-10', 2, 2)];
        $this->now = '2018-12-03 06:30:00';
        $this->assertSame('sent', $this->service()->runDaily()['outcome']);
        $line = $this->fake->puts['In/NA02629_ALL_L702_D_20181203_1_1.txt'];
        $this->assertSame('C', $line[0]);
        // the instalment, the amount overdue and the months in arrears are all zero in the bytes themselves
        $this->assertSame('000000000', substr($line, 412, 9), 'amount overdue');
        $this->assertSame('000000000', substr($line, 421, 9), 'instalment amount');
        $this->assertSame('00', substr($line, 430, 2), 'months in arrears');
        $this->assertSame('C ', substr($line, 432, 2), 'status code');
        $this->assertSame(718, strlen($line));
    }

    public function testThePanelReportsTheState(): void
    {
        $panel = $this->service()->panel();
        $this->assertTrue($panel['enabled']);
        $this->assertTrue($panel['production']);
        $this->assertStringContainsString('NOT the full historic loan book', $panel['warning']);
        $this->assertStringContainsString('941', $panel['warning']);
        $this->assertSame('2026-10-29', $panel['coverage_from']);
        $this->assertArrayHasKey('tracked_loans', $panel);
        $this->assertArrayHasKey('awaiting_answer', $panel);
    }
}

/** In-memory CPL settings: never reads or writes the stored (possibly real) values. */
final class CplAutoFakeSetting extends CplSetting
{
    /** @var array<string,string> */
    private array $values;

    public function __construct(array $values)
    {
        parent::__construct();
        $this->values = $values;
    }

    public function get(string $key, string $default = ''): string
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null ? (string) $this->values[$key] : $default;
    }

    public function put(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    /** @var string[] keys whose write throws */
    public array $failKeys = [];

    public function set(string $key, ?string $value, ?int $userId): void
    {
        if (in_array($key, $this->failKeys, true)) {
            throw new \RuntimeException('simulated settings write failure');
        }
        $this->values[$key] = $value;
    }
}

/** A batch model whose write of the "Submitted" status fails (the database going away after the upload). */
final class CplAutoFailingBatch extends CplBatch
{
    public bool $failSubmitted = false;

    public function updateRecord(int $id, array $data): bool
    {
        if ($this->failSubmitted && ($data['status'] ?? '') === 'Submitted') {
            throw new \RuntimeException('simulated write failure');
        }
        return parent::updateRecord($id, $data);
    }
}

/** Public Defaults SFTP settings that never read the stored host, user or secret. */
final class CplAutoFakePublicSetting extends CreditinfoPublicDefaultSetting
{
    private array $values;
    private ?string $secret;

    public function __construct(array $values, ?string $secret)
    {
        parent::__construct();
        $this->values = $values;
        $this->secret = $secret;
    }

    public function get(string $key, string $default = ''): string
    {
        if ($key === 'sftp_secret') {
            return $this->secret === null ? '' : 'stored';
        }
        return array_key_exists($key, $this->values) ? (string) $this->values[$key] : $default;
    }

    public function getDecryptedSftpSecret(): ?string
    {
        return $this->secret;
    }
}

final class CplAutoFakeSftp
{
    /** @var array<int, array{0:string,1:?string}> */
    public array $logins = [];
    /** @var array<string,string> */
    public array $puts = [];
    public bool $loginOk = true;
    public ?\Throwable $putThrows = null;
    public bool $putOk = true;
    public ?\Throwable $connectThrows = null;
    /** @var callable|null called after a good put() (e.g. to simulate the database changing under the run) */
    public $onPut = null;

    public function login(string $username, ?string $password = null): bool
    {
        $this->logins[] = [$username, $password];
        return $this->loginOk;
    }

    public function put(string $path, string $data): bool
    {
        if ($this->putThrows !== null) {
            throw $this->putThrows;
        }
        if (!$this->putOk) {
            return false;
        }
        $this->puts[$path] = $data;
        if ($this->onPut !== null) {
            ($this->onPut)();
        }
        return true;
    }

    public function getLastSFTPError(): string
    {
        return '';
    }

    public function disconnect(): void
    {
    }
}

/** The live SFTP service, with "is this a demo or sandbox copy?" under the test's control (never reads the real DemoMode). */
final class CplAutoTestSftp extends CplSftpService
{
    public bool $refuse = false;

    protected function instanceRefusesLive(): bool
    {
        return $this->refuse;
    }
}

/** The service, with the daily file replaceable so the final pre-upload check can be shown a broken file. */
final class CplAutoTestableService extends CplAutoSubmissionService
{
    public ?string $brokenDailyContent = null;

    protected function buildDailyContent(array $records, string $supplier, string $today): string
    {
        return $this->brokenDailyContent ?? parent::buildDailyContent($records, $supplier, $today);
    }
}
