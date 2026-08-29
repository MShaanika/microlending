<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Models\SlaInstance;
use App\Services\EscalationService;
use App\Services\SlaService;
use PHPUnit\Framework\TestCase;

/** Runs against the local dev database -- see tests/bootstrap.php. */
class SlaServiceTest extends TestCase
{
    private const POLICY_KEY = 'phpunit_sla_test_policy';
    private const RESOURCE_TYPE = 'phpunit_test_resource';
    private const RESOURCE_ID = 100;
    private int $policyId;
    private array $createdInstanceIds = [];

    protected function setUp(): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM sla_policies WHERE policy_key = ?')->execute([self::POLICY_KEY]);
        $db->prepare(
            "INSERT INTO sla_policies (policy_key, policy_name, module, resource_type, duration_minutes, business_hours_aware, at_risk_threshold_percent, is_active)
             VALUES (?, 'PHPUnit SLA Test', 'Test', ?, 60, 0, 75, 1)"
        )->execute([self::POLICY_KEY, self::RESOURCE_TYPE]);
        $this->policyId = (int) $db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $db->prepare("DELETE FROM exceptions WHERE resource_type = ? AND resource_id = ?")->execute([self::RESOURCE_TYPE, self::RESOURCE_ID]);
        foreach ($this->createdInstanceIds as $id) {
            $db->prepare('DELETE FROM sla_instances WHERE id = ?')->execute([$id]);
        }
        $this->createdInstanceIds = [];
        $db->prepare('DELETE FROM sla_escalations WHERE policy_id = ?')->execute([$this->policyId]);
        $db->prepare('DELETE FROM sla_policies WHERE id = ?')->execute([$this->policyId]);
    }

    private function start(): int
    {
        $id = SlaService::start(self::POLICY_KEY, self::RESOURCE_TYPE, self::RESOURCE_ID);
        $this->createdInstanceIds[] = $id;
        return $id;
    }

    public function testDueTimeIsComputedFromTheDuration(): void
    {
        $id = $this->start();
        $row = (new SlaInstance())->find($id);

        $expectedDue = strtotime($row['started_at']) + 60 * 60; // 60-minute policy
        $this->assertEqualsWithDelta($expectedDue, strtotime($row['due_at']), 5);
    }

    public function testInactivePolicyYieldsNoInstance(): void
    {
        Database::connection()->prepare('UPDATE sla_policies SET is_active = 0 WHERE id = ?')->execute([$this->policyId]);
        $this->assertNull(SlaService::start(self::POLICY_KEY, self::RESOURCE_TYPE, self::RESOURCE_ID));
    }

    public function testPauseThenResumeExtendsTheDueDateByThePausedDuration(): void
    {
        $id = $this->start();
        $model = new SlaInstance();
        $before = $model->find($id);

        SlaService::pause($id);
        $this->assertSame('PAUSED', $model->find($id)['status']);

        // Simulate 10 minutes having actually passed while paused.
        Database::connection()->prepare('UPDATE sla_instances SET paused_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 600), $id]);

        SlaService::resume($id);
        $after = $model->find($id);

        $this->assertSame('ON_TRACK', $after['status']);
        $this->assertGreaterThan(strtotime($before['due_at']), strtotime($after['due_at']));
    }

    public function testBreachIsDetectedOncePastDueDate(): void
    {
        $id = $this->start();
        Database::connection()->prepare('UPDATE sla_instances SET due_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 60), $id]);

        $model = new SlaInstance();
        $newStatus = SlaService::refreshStatus($model->find($id));

        $this->assertSame('BREACHED', $newStatus);
        $this->assertSame('BREACHED', $model->find($id)['status']);
    }

    public function testCompletionClosesTheInstance(): void
    {
        $this->start();
        SlaService::completeForResource(self::RESOURCE_TYPE, self::RESOURCE_ID);

        $instance = (new SlaInstance())->findOpenByResource(self::RESOURCE_TYPE, self::RESOURCE_ID);
        $this->assertNull($instance); // no longer "open" -- it's COMPLETED
    }

    public function testEscalationFiresOnceAtEachThresholdAndCreatesAnException(): void
    {
        Database::connection()->prepare(
            "INSERT INTO sla_escalations (policy_id, threshold_percent, action, exception_severity, is_active) VALUES (?, 50, 'CREATE_EXCEPTION', 'Medium', 1)"
        )->execute([$this->policyId]);

        $id = $this->start();
        // Shift the whole window back so 40 of the 60 total minutes have
        // elapsed (66%, past the 50% threshold) while keeping the total
        // duration at 60 minutes -- started_at and due_at must move
        // together, or percentElapsed()'s total-minutes denominator
        // changes along with it.
        Database::connection()->prepare('UPDATE sla_instances SET started_at = ?, due_at = ? WHERE id = ?')
            ->execute([
                date('Y-m-d H:i:s', time() - 40 * 60),
                date('Y-m-d H:i:s', time() + 20 * 60),
                $id,
            ]);

        $model = new SlaInstance();
        $instance = $model->find($id);

        EscalationService::evaluate($instance);
        $countStmt = Database::connection()->prepare('SELECT COUNT(*) FROM exceptions WHERE resource_type = ? AND resource_id = ?');
        $countStmt->execute([self::RESOURCE_TYPE, self::RESOURCE_ID]);
        $this->assertSame(1, (int) $countStmt->fetchColumn());

        // Evaluating again must not fire the same threshold twice (Part 21: no notification storms).
        EscalationService::evaluate($model->find($id));
        $countStmt->execute([self::RESOURCE_TYPE, self::RESOURCE_ID]);
        $this->assertSame(1, (int) $countStmt->fetchColumn());
    }
}
