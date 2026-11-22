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

    /** A warning e-mail that really was Sent (purge refuses to delete otherwise). */
    private function sentWarning(int $daysAgo = 10): void
    {
        $this->db->exec("INSERT INTO notification_queue (channel, recipient_name, recipient_contact, subject, message, source_module, source_table, source_id, status)
            VALUES ('Email', 'T', 't@example.com', 's', 'm', 'Application Purge', 'application_purge_warning', 900000099, 'Sent')");
        $this->db->exec("UPDATE notification_queue SET created_at = NOW() - INTERVAL $daysAgo DAY WHERE source_id = 900000099 AND source_module = 'Application Purge'");
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
