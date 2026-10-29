<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\RetentionPolicy;
use App\Services\RetentionService;
use PHPUnit\Framework\TestCase;

/**
 * Stored credit reports are deleted by the retention policies: report content + PDF go 90 days after the decision (180 days after the check
 * when no decision is ever recorded), the audit row survives and is stamped purged_at, and a legal hold stops the deletion. Recent reports are
 * untouched. Throw-away rows on the local dev database, removed afterwards.
 */
class CreditReportRetentionTest extends TestCase
{
    private \PDO $db;
    private array $cacheIds = [];
    private array $consentIds = [];
    private array $holds = [];
    private int $applicationId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->applicationId = (int) $this->db->query('SELECT id FROM loan_applications ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->assertGreaterThan(0, $this->applicationId, 'needs one loan application in the dev database');
    }

    protected function tearDown(): void
    {
        foreach ($this->holds as $id) {
            $this->db->prepare('DELETE FROM legal_holds WHERE id = ?')->execute([$id]);
        }
        foreach ($this->cacheIds as $id) {
            $this->db->prepare('DELETE FROM creditinfo_report_content WHERE report_cache_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM creditinfo_report_cache WHERE id = ?')->execute([$id]);
        }
        foreach ($this->consentIds as $id) {
            $this->db->prepare('DELETE FROM credit_bureau_consents WHERE id = ?')->execute([$id]);
        }
    }

    /** a credit check (audit row + stored content) */
    private function check(?string $decisionAt, string $createdAt): array
    {
        $this->db->prepare(
            "INSERT INTO credit_bureau_consents (application_id, consent_given, consent_method, recorded_by, recorded_at) VALUES (?, 1, 'Verbal', ?, NOW())"
        )->execute([$this->applicationId, $this->userId]);
        $consentId = (int) $this->db->lastInsertId();
        $this->consentIds[] = $consentId;
        $this->db->prepare(
            "INSERT INTO creditinfo_report_cache (application_id, consent_id, requested_by, national_id_used, report_status, decision_at, created_at) VALUES (?, ?, ?, ?, 'Finished', ?, ?)"
        )->execute([$this->applicationId, $consentId, $this->userId, 'PHPUNIT-' . uniqid(), $decisionAt, $createdAt]);
        $cacheId = (int) $this->db->lastInsertId();
        $this->cacheIds[] = $cacheId;
        $this->db->prepare(
            'INSERT INTO creditinfo_report_content (report_cache_id, report_content_encrypted, pdf_content_encrypted, decision_at, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$cacheId, 'enc-report', 'enc-pdf', $decisionAt, $createdAt]);
        return ['cache_id' => $cacheId, 'content_id' => (int) $this->db->lastInsertId()];
    }

    private function contentExists(int $contentId): bool
    {
        return (bool) $this->db->query('SELECT 1 FROM creditinfo_report_content WHERE id = ' . $contentId)->fetchColumn();
    }

    private function purgedAt(int $cacheId): ?string
    {
        $value = $this->db->query('SELECT purged_at FROM creditinfo_report_cache WHERE id = ' . $cacheId)->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    private function policy(string $key): array
    {
        $stmt = $this->db->prepare('SELECT * FROM retention_policies WHERE policy_key = ?');
        $stmt->execute([$key]);
        $policy = $stmt->fetch();
        $this->assertNotFalse($policy, "policy $key must exist (database/creditinfo_report_retention_active.sql)");
        return $policy;
    }

    public function testThePoliciesAreActive(): void
    {
        $decided = $this->policy('creditinfo_report_content_purge');
        $undecided = $this->policy('creditinfo_report_content_undecided_purge');
        foreach ([$decided, $undecided] as $p) {
            $this->assertSame(1, (int) $p['is_active']);
            $this->assertSame(1, (int) $p['legal_hold_supported']);
            $this->assertSame('creditinfo_report_content', $p['resource_table']);
        }
        $this->assertSame(90, (int) $decided['retention_days']);
        $this->assertSame(180, (int) $undecided['retention_days']);
    }

    public function testContentIsDeletedAfterTheDecisionWindowAndTheAuditRowIsKept(): void
    {
        $old = $this->check(date('Y-m-d H:i:s', strtotime('-100 days')), date('Y-m-d H:i:s', strtotime('-110 days')));
        $recent = $this->check(date('Y-m-d H:i:s', strtotime('-10 days')), date('Y-m-d H:i:s', strtotime('-12 days')));

        RetentionService::execute($this->policy('creditinfo_report_content_purge'), null);

        $this->assertFalse($this->contentExists($old['content_id']), 'past 90 days after the decision: content and PDF are gone');
        $this->assertTrue($this->contentExists($recent['content_id']), 'a recent decision keeps its report');
        $this->assertNotNull($this->purgedAt($old['cache_id']), 'the audit row of the check survives and says its content was purged');
        $this->assertNull($this->purgedAt($recent['cache_id']));
    }

    public function testAReportWithNoDecisionIsDeletedByTheBackstopOnly(): void
    {
        $undecidedOld = $this->check(null, date('Y-m-d H:i:s', strtotime('-200 days')));
        $undecidedRecent = $this->check(null, date('Y-m-d H:i:s', strtotime('-100 days')));

        RetentionService::execute($this->policy('creditinfo_report_content_purge'), null);
        $this->assertTrue($this->contentExists($undecidedOld['content_id']), 'the after-decision policy never touches a report with no decision');

        RetentionService::execute($this->policy('creditinfo_report_content_undecided_purge'), null);
        $this->assertFalse($this->contentExists($undecidedOld['content_id']), '180 days after the check with no decision: deleted');
        $this->assertTrue($this->contentExists($undecidedRecent['content_id']), 'a 100-day-old undecided report is still inside the window');
    }

    public function testALegalHoldStopsTheDeletion(): void
    {
        $held = $this->check(date('Y-m-d H:i:s', strtotime('-120 days')), date('Y-m-d H:i:s', strtotime('-130 days')));
        $this->holds[] = (new RetentionPolicy())->placeHold('creditinfo_report_content', $held['content_id'], 'phpunit dispute', $this->userId);

        $result = RetentionService::execute($this->policy('creditinfo_report_content_purge'), null);

        $this->assertTrue($this->contentExists($held['content_id']), 'a report under legal hold is kept');
        $this->assertGreaterThanOrEqual(1, $result['held']);
        $this->assertNull($this->purgedAt($held['cache_id']));
    }
}
