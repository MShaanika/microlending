<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\ApplicantCorrectionSms;
use App\Services\ApplicationPurgeService;
use PHPUnit\Framework\TestCase;

/**
 * Clean-up of unapproved applications + the applicant correction SMS, against the
 * local development database. Fixtures use the application_no prefix APP-PRG- and
 * ids >= 900000000 for queue rows, and are removed in tearDown.
 */
class ApplicationPurgeTest extends TestCase
{
    private \PDO $db;
    private ApplicationPurgeService $svc;
    /** @var string[] */
    private array $dirs = [];

    /** @var array<string,?string> */
    private array $savedSettings = [];

    protected function setUp(): void
    {
        $this->db = Database::connection();
        foreach (['application_retention_days', 'application_purge_warning_days'] as $k) {
            $v = $this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $v->execute([$k]);
            $this->savedSettings[$k] = $v->fetchColumn() === false ? null : (string) $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = '$k'")->fetchColumn();
        }
        $this->svc = new ApplicationPurgeService($this->db);
        $this->wipe();
        $this->setSetting('application_retention_days', '30');
        $this->setSetting('application_purge_warning_days', '7');
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach ($this->savedSettings as $k => $v) {
            if ($v !== null) {
                $this->setSetting($k, $v);
            }
        }
        foreach ($this->dirs as $d) {
            if (is_dir($d)) {
                foreach (glob($d . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
    }

    private function wipe(): void
    {
        $this->db->exec("DELETE FROM notification_queue WHERE source_id >= 900000000 AND source_module IN ('Applicant SMS','Application Purge')");
        $this->db->exec("DELETE FROM notification_queue WHERE source_module = 'Application Purge' AND source_table = 'application_purge_warning' AND source_id = 900000099");
        $ids = $this->db->query("SELECT id FROM loan_applications WHERE application_no LIKE 'APP-PRG-%'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->db->exec("DELETE FROM creditinfo_report_content WHERE report_cache_id IN (SELECT id FROM creditinfo_report_cache WHERE application_id = $id)");
            $this->db->exec("DELETE FROM creditinfo_report_cache WHERE application_id = $id");
            $this->db->exec("DELETE FROM credit_bureau_consents WHERE application_id = $id");
            $this->db->exec("DELETE FROM loan_followups WHERE application_id = $id");
            $this->db->exec("DELETE FROM legal_holds WHERE resource_table = 'loan_applications' AND resource_id = $id");
            $this->db->exec("DELETE FROM loan_applications WHERE id = $id");
        }
    }

    private function setSetting(string $key, string $value): void
    {
        $this->db->prepare('INSERT INTO system_settings (setting_key, setting_value, module_name) VALUES (?, ?, \'Retention\') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
    }

    /** @return int new application id; last status change $daysAgo days ago */
    private function app(string $no, string $status, int $daysAgo, ?int $warnedDaysAgo = null): int
    {
        $this->db->prepare("INSERT INTO loan_applications (application_no, status, applicant_first_name, applicant_last_name, applicant_phone, applicant_id_number, created_at, purge_warned_at)
            VALUES (?, ?, 'Test', 'Person', '0811234567', '90010112345', NOW() - INTERVAL ? DAY, " . ($warnedDaysAgo === null ? 'NULL' : 'NOW() - INTERVAL ' . (int) $warnedDaysAgo . ' DAY') . ')')
            ->execute(['APP-PRG-' . $no, $status, $daysAgo]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * A warning e-mail that really was Sent (purge refuses to delete otherwise). Queued $daysAgo days ago (this must match
     * the warned-at stamp), actually sent $sentDaysAgo days ago (default: same time), written like the dispatcher does:
     * sent_at from PHP's date(), status Sent. $sentDaysAgo = null leaves sent_at empty (status Sent but no timestamp).
     */
    private function sentWarning(int $daysAgo = 10, ?int $sentDaysAgo = -1, string $status = 'Sent'): void
    {
        $sentDaysAgo = $sentDaysAgo === -1 ? $daysAgo : $sentDaysAgo;
        $this->db->exec("INSERT INTO notification_queue (channel, recipient_name, recipient_contact, subject, message, source_module, source_table, source_id, status)
            VALUES ('Email', 'T', 't@example.com', 's', 'm', 'Application Purge', 'application_purge_warning', 900000099, '$status')");
        $id = (int) $this->db->lastInsertId();
        $this->db->exec("UPDATE notification_queue SET created_at = NOW() - INTERVAL $daysAgo DAY WHERE id = $id");
        if ($sentDaysAgo !== null) {
            $this->db->prepare('UPDATE notification_queue SET sent_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', strtotime('-' . $sentDaysAgo . ' days')), $id]);
        }
    }

    private function stamp(int $id): ?string
    {
        $v = $this->db->query("SELECT purge_warned_at FROM loan_applications WHERE id = $id")->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private function wipeQueue(): void
    {
        $this->db->exec("DELETE FROM notification_queue WHERE source_module = 'Application Purge' AND source_table = 'application_purge_warning' AND source_id = 900000099");
    }

    private function eligibleWarnedNos(): array
    {
        return array_column($this->svc->eligible(30, true), 'application_no');
    }

    private function followup(int $appId, string $status, ?string $approved, ?string $plans = null, ?string $disbursed = null): void
    {
        $this->db->prepare('INSERT INTO loan_followups (application_id, application_no, status, approved, plans_to_approve, disbursed) VALUES (?, (SELECT application_no FROM loan_applications WHERE id = ?), ?, ?, ?, ?)')
            ->execute([$appId, $appId, $status, $approved, $plans, $disbursed]);
    }

    private function eligibleNos(): array
    {
        return array_values(array_filter(array_column($this->svc->eligible(30), 'application_no'), fn ($n) => str_starts_with($n, 'APP-PRG-')));
    }

    public function testAgeAndStatusRules(): void
    {
        $this->app('OLD', 'Rejected', 40);
        $this->app('NEW', 'Rejected', 10);
        $this->app('APPROVED', 'Approved', 400);
        $this->app('CONVERTED', 'Converted to Loan', 400);
        $this->assertSame(['APP-PRG-OLD'], $this->eligibleNos());
    }

    public function testRetentionDaysHasAFloor(): void
    {
        $this->setSetting('application_retention_days', '0');
        $this->assertGreaterThanOrEqual(14, ApplicationPurgeService::retentionDays(), 'typing 0 must never purge everything');
    }

    public function testLoanQuestionGuardProtectsRealLoans(): void
    {
        $a = $this->app('APPROVEDQ', 'Submitted', 90);
        $this->followup($a, 'Open', 'Yes');
        $b = $this->app('DISBURSED', 'Submitted', 90);
        $this->followup($b, 'Closed', 'Yes', null, 'Yes');
        $c = $this->app('OPENQ', 'Submitted', 90);
        $this->followup($c, 'Open', null);
        $d = $this->app('SAIDNO', 'Submitted', 90);
        $this->followup($d, 'Open', 'No', 'No');
        $e = $this->app('REJECTEDYES', 'Rejected', 90);
        $this->followup($e, 'Open', 'Yes');

        $f = $this->app('NOFOLLOWUP', 'Submitted', 90);
        $g = $this->app('REJECTEDOPENQ', 'Rejected', 90);
        $this->followup($g, 'Open', null);
        $this->assertSame(['APP-PRG-SAIDNO'], $this->eligibleNos());
        $blocked = array_column($this->svc->blockedByLoanQuestion(30), 'application_no');
        $this->assertContains('APP-PRG-OPENQ', $blocked, 'flagged to staff, never deleted');
        $this->assertContains('APP-PRG-NOFOLLOWUP', $blocked, 'an open application with no loan question may be a real legacy loan');
        $this->assertContains('APP-PRG-REJECTEDOPENQ', $blocked, 'an unanswered question blocks closed statuses too');
        $this->assertNotContains('APP-PRG-APPROVEDQ', $blocked);
    }

    public function testLegalHoldAndLinkedBorrowerProtect(): void
    {
        $a = $this->app('HELD', 'Rejected', 90);
        $uid = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->prepare("INSERT INTO legal_holds (resource_table, resource_id, reason, placed_by) VALUES ('loan_applications', ?, 'test', ?)")->execute([$a, $uid]);
        $b = $this->app('BORROWER', 'Rejected', 90);
        $bid = $this->db->query('SELECT id FROM borrowers ORDER BY id LIMIT 1')->fetchColumn();
        if ($bid) {
            $this->db->prepare('UPDATE loan_applications SET borrower_id = ? WHERE id = ?')->execute([$bid, $b]);
        }
        $this->assertNotContains('APP-PRG-HELD', $this->eligibleNos());
        if ($bid) {
            $this->assertNotContains('APP-PRG-BORROWER', $this->eligibleNos());
        }
    }

    public function testPurgeRefusesWithoutAWarningAndWorksAfterIt(): void
    {
        $id = $this->app('WARN', 'Rejected', 60);
        $res = $this->svc->purge($id);
        $this->assertFalse($res['ok'], 'never deleted without a prior warning');
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM loan_applications WHERE id = $id")->fetchColumn());

        $this->db->exec("UPDATE loan_applications SET purge_warned_at = NOW() - INTERVAL 2 DAY WHERE id = $id");
        $this->assertFalse($this->svc->purge($id)['ok'], 'warned only 2 days ago, 7 required');

        $this->db->exec("UPDATE loan_applications SET purge_warned_at = NOW() - INTERVAL 8 DAY WHERE id = $id");
        $this->assertFalse($this->svc->purge($id)['ok'], 'a warning that was never delivered does not count');
        $this->sentWarning(8);
        $res = $this->svc->purge($id);
        $this->assertTrue($res['ok']);
        $this->assertSame('delete', $res['mode']);
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM loan_applications WHERE id = $id")->fetchColumn());
    }

    public function testCreditinfoRecordMakesATombstoneNotADelete(): void
    {
        $this->sentWarning();
        $id = $this->app('TOMB', 'Rejected', 60, 10);
        $uid = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->prepare("INSERT INTO credit_bureau_consents (application_id, consent_given, consent_method, recorded_by, recorded_at) VALUES (?, 1, 'Signed Application Form', ?, NOW())")->execute([$id, $uid]);

        $res = $this->svc->purge($id);
        $this->assertTrue($res['ok']);
        $this->assertSame('tombstone', $res['mode']);
        $row = $this->db->query("SELECT * FROM loan_applications WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'row kept because the consent record must never be deleted');
        $this->assertNull($row['applicant_first_name']);
        $this->assertNull($row['applicant_id_number']);
        $this->assertNull($row['applicant_phone']);
        $this->assertNotNull($row['purged_at']);
        $this->assertNotContains('APP-PRG-TOMB', $this->eligibleNos(), 'a tombstone is never processed again');
    }

    public function testFilesAreRemovedAfterwardsButNotWhenABorrowerSharesThem(): void
    {
        $this->sentWarning();
        $id = $this->app('FILES', 'Rejected', 60, 10);
        $dir = STORAGE_PATH . '/uploads/loan_applications/APP-PRG-FILES';
        @mkdir($dir, 0775, true);
        $this->dirs[] = $dir;
        file_put_contents($dir . '/payslip.txt', 'x');
        $this->assertSame(1, $this->svc->plan($id)['files']);
        $this->svc->purge($id);
        $this->assertFileDoesNotExist($dir . '/payslip.txt');
        $this->assertDirectoryDoesNotExist($dir);
        $q = glob(STORAGE_PATH . '/purged/' . date('Ymd') . '/APP-PRG-FILES/*');
        $this->assertCount(1, $q, 'moved to quarantine, not destroyed');
        foreach ($q as $f) { @unlink($f); }
        @rmdir(STORAGE_PATH . '/purged/' . date('Ymd') . '/APP-PRG-FILES');
        @rmdir(STORAGE_PATH . '/purged/' . date('Ymd'));
    }

    public function testDryRunPlanWritesNothing(): void
    {
        $id = $this->app('PLAN', 'Rejected', 60, 10);
        $plan = $this->svc->plan($id);
        $this->assertSame('delete', $plan['mode']);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM loan_applications WHERE id = $id")->fetchColumn());
    }

    public function testExtendMovesTheClockAndClearsTheWarning(): void
    {
        $id = $this->app('EXT', 'Rejected', 60, 10);
        $this->svc->extend($id, null);
        $this->assertNotContains('APP-PRG-EXT', $this->eligibleNos());
        $this->assertNull($this->db->query("SELECT purge_warned_at FROM loan_applications WHERE id = $id")->fetchColumn() ?: null);
    }

    public function testStaleWarningsAreClearedWhenNoLongerEligible(): void
    {
        $id = $this->app('STALE', 'Approved', 60, 3);
        $this->svc->clearStaleWarnings();
        $this->assertFalse((bool) $this->db->query("SELECT purge_warned_at FROM loan_applications WHERE id = $id")->fetchColumn());
    }

    // ---- warning proof (A), digest stamping (B), stale stamps (C), ever-approved (D)

    public function testWarningMustHaveBeenSentAtLeastWarningDaysAgo(): void
    {
        $id = $this->app('LATE', 'Rejected', 60, 10);
        $this->sentWarning(10, 2); // queued 10 days ago, but only really sent 2 days ago (dispatch was down)
        $this->assertFalse($this->svc->purge($id)['ok'], 'the clock starts when the e-mail is SENT');
        $this->assertNotContains('APP-PRG-LATE', $this->eligibleWarnedNos());
        $this->assertNotNull($this->stamp($id));
        $this->wipeQueue();
        $this->sentWarning(10, 8);
        $this->assertTrue($this->svc->purge($id)['ok'], 'sent 8 days ago, 7 required');
    }

    public function testStatusSentWithoutSentAtIsNotProof(): void
    {
        $id = $this->app('NOSENTAT', 'Rejected', 60, 10);
        $this->sentWarning(10, null);
        $this->assertFalse($this->svc->purge($id)['ok']);
        $this->assertNotContains('APP-PRG-NOSENTAT', $this->eligibleWarnedNos());
    }

    public function testSentWarningFromAnotherRunDoesNotCountForThisStamp(): void
    {
        $id = $this->app('OTHERRUN', 'Rejected', 60, 10);
        $this->sentWarning(12); // a digest sent 2 days before this application was stamped
        $this->assertFalse($this->svc->purge($id)['ok']);
    }

    public function testSentAtIsComparedInTheAppClockNotTheDatabaseClock(): void
    {
        // sent_at is written in PHP time: 7 days + 1 minute ago counts, 6 days 23 hours ago does not.
        $id = $this->app('CLOCK', 'Rejected', 60, 10);
        $this->sentWarning(10, null);
        $qid = (int) $this->db->query("SELECT MAX(id) FROM notification_queue WHERE source_id = 900000099 AND source_module = 'Application Purge'")->fetchColumn();
        $upd = $this->db->prepare('UPDATE notification_queue SET sent_at = ? WHERE id = ?');
        $upd->execute([date('Y-m-d H:i:s', strtotime('-7 days -1 minute')), $qid]);
        $this->assertContains('APP-PRG-CLOCK', $this->eligibleWarnedNos());
        $upd->execute([date('Y-m-d H:i:s', strtotime('-6 days -23 hours')), $qid]);
        $this->assertNotContains('APP-PRG-CLOCK', $this->eligibleWarnedNos());
    }

    public function testWarningTooOldIsNotTrustedAndIsCleared(): void
    {
        $id = $this->app('STAMPOLD', 'Rejected', 60, 15); // warning_days + 7 = 14
        $this->sentWarning(15);
        $this->assertFalse($this->svc->purge($id)['ok'], 'a 15 day old warning is stale');
        $this->assertNotContains('APP-PRG-STAMPOLD', $this->eligibleWarnedNos());
        $this->svc->clearStaleWarnings();
        $this->assertNull($this->stamp($id), 'stale stamp removed so the next run re-warns');
    }

    public function testStampWithoutASentEmailIsClearedAfterWarningDaysPlusOne(): void
    {
        $notSent = $this->app('NOTSENT', 'Rejected', 60, 9);
        $waiting = $this->app('WAITING', 'Rejected', 60, 5);
        $this->sentWarning(9, null, 'Failed'); // a digest that never went out
        $this->svc->clearStaleWarnings();
        $this->assertNull($this->stamp($notSent), 'older than warning_days + 1 and no Sent e-mail: cleared');
        $this->assertNotNull($this->stamp($waiting), 'only 5 days old: the e-mail may still be on its way');
        $this->assertContains('APP-PRG-NOTSENT', array_column($this->svc->dueSoon(), 'application_no'), 'due again, so the next warn() re-queues the digest');
    }

    public function testStampWithADeliveredEmailIsKept(): void
    {
        $good = $this->app('GOODSENT', 'Rejected', 60, 9);
        $this->sentWarning(9);
        $this->svc->clearStaleWarnings();
        $this->assertNotNull($this->stamp($good));
    }

    public function testLateSentWarningKeepsItsStampWhilePurgeWaits(): void
    {
        $id = $this->app('LATE2', 'Rejected', 60, 10);
        $this->sentWarning(10, 2);
        $this->svc->clearStaleWarnings();
        $this->assertNotNull($this->stamp($id), 'delivered late but delivered: the stamp stays, purge just waits');
        $this->assertFalse($this->svc->purge($id)['ok']);
    }

    public function testEverApprovedApplicationsAreNeverPurged(): void
    {
        $uid = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $a = $this->app('WASAPPR1', 'Rejected', 60, 10);
        $this->db->exec("UPDATE loan_applications SET approved_at = NOW() - INTERVAL 50 DAY WHERE id = $a");
        $b = $this->app('WASAPPR2', 'Rejected', 60, 10);
        $this->db->prepare('UPDATE loan_applications SET approved_by = ? WHERE id = ?')->execute([$uid, $b]);
        $c = $this->app('WASAPPR3', 'Rejected', 60, 10);
        $hist = $this->db->prepare('INSERT INTO loan_application_status_history (application_id, old_status, new_status, changed_by, changed_at) VALUES (?, ?, ?, ?, NOW() - INTERVAL ? DAY)');
        $hist->execute([$c, 'Screening', 'Approved', $uid, 59]);
        $hist->execute([$c, 'Approved', 'Rejected', $uid, 55]);
        $d = $this->app('NEVERAPPR', 'Rejected', 60, 10);
        $this->sentWarning(10);
        foreach ([$a, $b, $c] as $id) {
            $this->assertFalse($this->svc->purge($id)['ok']);
            $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) FROM loan_applications WHERE id = $id")->fetchColumn());
        }
        $this->assertSame(['APP-PRG-NEVERAPPR'], $this->eligibleNos());
        $blocked = array_column($this->svc->blockedByLoanQuestion(30), 'application_no');
        $this->assertSame([], array_values(array_filter($blocked, fn ($n) => str_starts_with($n, 'APP-PRG-WASAPPR'))), 'never even flagged');
        $this->svc->clearStaleWarnings();
        $this->assertNull($this->stamp($a), 'the warning of a once-approved application is withdrawn');
        $this->assertNotNull($this->stamp($d));
        $this->assertTrue($this->svc->purge($d)['ok']);
    }

    public function testDigestStampsOnlyTheApplicationsItNames(): void
    {
        $hasRecipient = false;
        foreach (['applications.screen', 'loans.followups'] as $perm) {
            foreach ((new \App\Models\User())->usersWithPermission($perm) as $u) {
                $hasRecipient = $hasRecipient || !empty($u['email']);
            }
        }
        if (!$hasRecipient) {
            $this->markTestSkipped('no user with an e-mail address holds applications.screen / loans.followups locally');
        }
        $before = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM notification_queue')->fetchColumn();
        $unstamped = $this->db->query("SELECT id FROM loan_applications WHERE purge_warned_at IS NULL AND application_no NOT LIKE 'APP-PRG-%'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->db->exec("DELETE FROM notification_queue WHERE source_module = 'Application Purge' AND source_table = 'application_purge_warning' AND source_id = " . (int) date('Ymd'));
        try {
            for ($i = 1; $i <= 35; $i++) {
                $this->app(sprintf('DIG%02d', $i), 'Rejected', 4000 - $i); // very old, so they sort ahead of any real data
            }
            $w = $this->svc->warn();
            $this->assertGreaterThan(0, $w['emails']);
            $this->assertSame(ApplicationPurgeService::WARN_PER_RUN, $w['warned'], '35 were due; only the ones named are stamped');
            $msg = (string) $this->db->query("SELECT message FROM notification_queue WHERE id > $before AND source_module = 'Application Purge' ORDER BY id LIMIT 1")->fetchColumn();
            preg_match_all('/^(APP-PRG-DIG\d\d) \(/m', $msg, $named);
            $this->assertCount(ApplicationPurgeService::WARN_PER_RUN, $named[1]);
            $stamped = $this->db->query("SELECT application_no FROM loan_applications WHERE application_no LIKE 'APP-PRG-DIG%' AND purge_warned_at IS NOT NULL ORDER BY application_no")->fetchAll(\PDO::FETCH_COLUMN);
            $sortedNamed = $named[1];
            sort($sortedNamed);
            $this->assertSame($sortedNamed, $stamped, 'exactly the named applications are stamped, none that was not named');
            $this->assertStringNotContainsString('more.', $msg, 'no unnamed tail');
        } finally {
            $this->db->exec("DELETE FROM notification_queue WHERE id > $before AND source_module = 'Application Purge'");
            if ($unstamped) {
                $this->db->exec('UPDATE loan_applications SET purge_warned_at = NULL WHERE id IN (' . implode(',', array_map('intval', $unstamped)) . ')');
            }
        }
    }

    // ---- applicant correction SMS

    private function sms(array $settings = []): ApplicantCorrectionSms
    {
        $settings += [ApplicantCorrectionSms::KEY_CONTACT => '081 000 0000'];
        return new ApplicantCorrectionSms(static fn (string $k, string $d): string => $settings[$k] ?? $d);
    }

    public function testCorrectionSmsQueuesOneShortGsmSafeMessage(): void
    {
        $r = $this->sms()->request(900000010, 'APP-PRG-9', 'Zoë “Test”', '0811234567', ['dob', 'phone'], 'café note', null);
        $this->assertTrue($r['queued'], $r['note']);
        $row = $this->db->query("SELECT * FROM notification_queue WHERE source_module='Applicant SMS' AND source_id=900000010")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('+264811234567', $row['recipient_contact']);
        $this->assertSame('SMS', $row['channel']);
        $this->assertLessThanOrEqual(160, strlen($row['message']));
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $row['message']);
        $this->assertStringContainsString('date of birth', $row['message']);
        $this->assertStringNotContainsString('90010112345', $row['message']);
    }

    public function testCorrectionSmsRefusalsSayWhy(): void
    {
        $this->assertStringContainsString('switched off', $this->sms([ApplicantCorrectionSms::KEY_ENABLED => '0'])->request(900000011, 'A', 'N', '0811234567', ['dob'], null, null)['note']);
        $this->assertStringContainsString('contact number', $this->sms([ApplicantCorrectionSms::KEY_CONTACT => ''])->request(900000011, 'A', 'N', '0811234567', ['dob'], null, null)['note']);
        $this->assertStringContainsString('no usable phone', $this->sms()->request(900000011, 'A', 'N', '', ['dob'], null, null)['note']);
        $this->assertStringContainsString('already Approved', $this->sms()->request(900000011, 'A', 'N', '0811234567', ['dob'], null, null, null, 'Approved')['note']);
        $this->assertStringContainsString('at least one', $this->sms()->request(900000011, 'A', 'N', '0811234567', [], null, null)['note']);
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM notification_queue WHERE source_module='Applicant SMS' AND source_id=900000011")->fetchColumn());
    }

    public function testCorrectionSmsDedupeAndCap(): void
    {
        $s = $this->sms();
        $this->assertTrue($s->request(900000012, 'A', 'N', '0811234567', ['dob'], null, null)['queued']);
        $this->assertFalse($s->request(900000012, 'A', 'N', '0811234567', ['dob'], null, null)['queued'], 'same text within 24 h');
        $this->assertTrue($s->request(900000012, 'A', 'N', '0811234567', ['phone'], null, null)['queued']);
        $this->assertTrue($s->request(900000012, 'A', 'N', '0811234567', ['gender'], null, null)['queued']);
        $this->assertStringContainsString('3 times', $s->request(900000012, 'A', 'N', '0811234567', ['address'], null, null)['note']);
    }

    public function testMessageStaysWithin160EvenWithEverythingSelected(): void
    {
        $msg = ApplicantCorrectionSms::buildMessage('APP-261122-ABCDEF', array_values(ApplicantCorrectionSms::REASONS), '081 000 0000', str_repeat('long note ', 10));
        $this->assertLessThanOrEqual(160, strlen($msg));
        $this->assertDoesNotMatchRegularExpression('/\\b[a-z]$/', rtrim($msg, '. '), 'never cut mid-word');
        $two = ApplicantCorrectionSms::buildMessage('APP-261122-ABCDEF', ['date of birth', 'mobile number'], '063 234 341');
        $this->assertStringContainsString('date of birth and mobile number', $two, 'two realistic reasons both fit');
    }
}
