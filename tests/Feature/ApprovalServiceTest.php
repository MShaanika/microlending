<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Session;
use App\Services\ApprovalService;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the local dev database (no separate test DB exists --
 * see tests/bootstrap.php). Uses a throwaway policy_key so it never
 * touches the real seeded loan_write_off_approval policy, and cleans up
 * every row it creates.
 */
class ApprovalServiceTest extends TestCase
{
    private const POLICY_KEY = 'phpunit_test_policy';
    private const PERMISSION = 'phpunit.test_permission';
    private int $policyId;
    private int $makerId = 1;
    private int $checkerId = 3;
    private array $createdRequestIds = [];

    protected function setUp(): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM approval_policies WHERE policy_key = ?')->execute([self::POLICY_KEY]);
        $db->prepare(
            "INSERT INTO approval_policies (policy_key, policy_name, module, resource_type, action_type, approver_permission, required_steps, is_active)
             VALUES (?, 'PHPUnit Test Policy', 'Test', 'test_resource', 'approve', ?, 1, 1)"
        )->execute([self::POLICY_KEY, self::PERMISSION]);
        $this->policyId = (int) $db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdRequestIds as $id) {
            $db->prepare('DELETE FROM approval_requests WHERE id = ?')->execute([$id]);
        }
        $this->createdRequestIds = [];
        $db->prepare('DELETE FROM approval_policies WHERE id = ?')->execute([$this->policyId]);
        Session::forget('user');
    }

    private function actAs(int $userId, array $permissions): void
    {
        Session::put('user', ['id' => $userId, 'permissions' => $permissions]);
    }

    private function submitRequest(int $makerId): int
    {
        $id = ApprovalService::request(self::POLICY_KEY, [
            'resource_id' => 1,
            'maker_user_id' => $makerId,
            'title' => 'PHPUnit test request',
            'amount' => 500.0,
        ]);
        $this->createdRequestIds[] = $id;
        return $id;
    }

    public function testMakerCannotApproveOwnRequest(): void
    {
        $id = $this->submitRequest($this->makerId);
        $this->actAs($this->makerId, [self::PERMISSION]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot approve your own request');
        ApprovalService::approve($id);
    }

    public function testAuthorizedCheckerCanApprove(): void
    {
        $id = $this->submitRequest($this->makerId);
        $this->actAs($this->checkerId, [self::PERMISSION]);

        $result = ApprovalService::approve($id);

        $this->assertSame('APPROVED', $result['status']);
        $this->assertNull($result['delegation']);
    }

    public function testUnauthorizedUserCannotApprove(): void
    {
        $id = $this->submitRequest($this->makerId);
        $this->actAs($this->checkerId, []); // holds neither the permission nor any delegation

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not authorized');
        ApprovalService::approve($id);
    }

    public function testRejectionWorksAndRequiresComments(): void
    {
        $id = $this->submitRequest($this->makerId);
        $this->actAs($this->checkerId, [self::PERMISSION]);

        try {
            ApprovalService::reject($id, '');
            $this->fail('Expected rejection without comments to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }

        ApprovalService::reject($id, 'Not enough evidence');

        $status = Database::connection()->prepare('SELECT status FROM approval_requests WHERE id = ?');
        $status->execute([$id]);
        $this->assertSame('REJECTED', $status->fetchColumn());
    }

    public function testReturnedRequestWorks(): void
    {
        $id = $this->submitRequest($this->makerId);
        $this->actAs($this->checkerId, [self::PERMISSION]);

        ApprovalService::returnForCorrection($id, 'Please attach supporting documents');

        $status = Database::connection()->prepare('SELECT status FROM approval_requests WHERE id = ?');
        $status->execute([$id]);
        $this->assertSame('RETURNED', $status->fetchColumn());
    }

    public function testInactivePolicyYieldsNoRequestInsteadOfBlockingTheCaller(): void
    {
        Database::connection()->prepare('UPDATE approval_policies SET is_active = 0 WHERE id = ?')->execute([$this->policyId]);

        $result = ApprovalService::request(self::POLICY_KEY, [
            'resource_id' => 99,
            'maker_user_id' => $this->makerId,
            'title' => 'Should not be created while the policy is off',
        ]);

        // null, not an exception -- the calling module's own pre-existing
        // check is meant to be the whole story when a policy is disabled
        // (Part 41's staged-rollout "off switch").
        $this->assertNull($result);
    }
}
