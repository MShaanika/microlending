<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Services\DelegationService;
use PHPUnit\Framework\TestCase;

/** Runs against the local dev database -- see tests/bootstrap.php. */
class DelegationServiceTest extends TestCase
{
    // Real accounts (System Administrator / Kodecamp Technologies) --
    // user 1 is Super Admin and genuinely holds approvals.approve via
    // Phase 1's blanket module grant, which several tests rely on.
    private int $delegatorId = 1;
    private int $delegateId = 3;
    private array $createdDelegationIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->createdDelegationIds as $id) {
            $db->prepare('DELETE FROM delegations WHERE id = ?')->execute([$id]);
        }
        $this->createdDelegationIds = [];
    }

    private function createDelegation(string $startsAt, string $endsAt, array $scopes, ?int $delegatorId = null): int
    {
        $id = DelegationService::create(
            $delegatorId ?? $this->delegatorId,
            $this->delegateId,
            $startsAt,
            $endsAt,
            'phpunit test delegation',
            $scopes,
            $delegatorId ?? $this->delegatorId
        );
        $this->createdDelegationIds[] = $id;
        return $id;
    }

    private function scope(string $permissionKey, ?float $amountLimit = null): array
    {
        return [['permission_key' => $permissionKey, 'module' => null, 'amount_limit' => $amountLimit, 'branch_id' => null]];
    }

    public function testDelegatorMustActuallyHoldTheDelegatedPermission(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not hold');
        $this->createDelegation(
            date('Y-m-d H:i:s', time() - 3600),
            date('Y-m-d H:i:s', time() + 3600),
            $this->scope('phpunit.totally_made_up_permission_nobody_has')
        );
    }

    public function testCannotDelegateToSelf(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot delegate to themselves');
        DelegationService::create(1, 1, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600), null, $this->scope('approvals.approve'), 1);
    }

    public function testNotActiveBeforeStart(): void
    {
        $this->createDelegation(
            date('Y-m-d H:i:s', time() + 3600), // starts an hour from now
            date('Y-m-d H:i:s', time() + 7200),
            $this->scope('approvals.approve')
        );

        $this->assertNull(DelegationService::activeDelegationGranting($this->delegateId, 'approvals.approve'));
    }

    public function testActiveDuringItsWindow(): void
    {
        $this->createDelegation(
            date('Y-m-d H:i:s', time() - 3600),
            date('Y-m-d H:i:s', time() + 3600),
            $this->scope('approvals.approve')
        );

        $granted = DelegationService::activeDelegationGranting($this->delegateId, 'approvals.approve');
        $this->assertNotNull($granted);
        $this->assertSame($this->delegatorId, (int) $granted['delegator_user_id']);
    }

    public function testExpiredAfterItsWindowEnds(): void
    {
        $this->createDelegation(
            date('Y-m-d H:i:s', time() - 7200),
            date('Y-m-d H:i:s', time() - 3600), // ended an hour ago
            $this->scope('approvals.approve')
        );

        $this->assertNull(DelegationService::activeDelegationGranting($this->delegateId, 'approvals.approve'));
    }

    public function testScopeIsEnforcedNotAWholeRoleCopy(): void
    {
        $this->createDelegation(
            date('Y-m-d H:i:s', time() - 3600),
            date('Y-m-d H:i:s', time() + 3600),
            $this->scope('approvals.approve')
        );

        $this->assertNotNull(DelegationService::activeDelegationGranting($this->delegateId, 'approvals.approve'));
        // Not granted for a different permission the delegator also holds
        // but which was never included in this delegation's scope.
        $this->assertNull(DelegationService::activeDelegationGranting($this->delegateId, 'delegations.view'));
    }

    public function testAmountLimitIsEnforced(): void
    {
        $this->createDelegation(
            date('Y-m-d H:i:s', time() - 3600),
            date('Y-m-d H:i:s', time() + 3600),
            $this->scope('approvals.approve', 1000.0)
        );

        $this->assertNotNull(DelegationService::activeDelegationGranting($this->delegateId, 'approvals.approve', 500));
        $this->assertNull(DelegationService::activeDelegationGranting($this->delegateId, 'approvals.approve', 5000));
    }

    public function testRevokedDelegationNoLongerGrantsAuthorityEvenWithinItsWindow(): void
    {
        $id = $this->createDelegation(
            date('Y-m-d H:i:s', time() - 3600),
            date('Y-m-d H:i:s', time() + 3600),
            $this->scope('approvals.approve')
        );

        DelegationService::revoke($id, $this->delegatorId, 'no longer needed');

        $this->assertNull(DelegationService::activeDelegationGranting($this->delegateId, 'approvals.approve'));
    }

    public function testCreationIsAudited(): void
    {
        $before = (int) Database::connection()->query("SELECT COUNT(*) FROM audit_logs WHERE module_name = 'Governance'")->fetchColumn();

        $this->createDelegation(
            date('Y-m-d H:i:s', time() - 3600),
            date('Y-m-d H:i:s', time() + 3600),
            $this->scope('approvals.approve')
        );

        $after = (int) Database::connection()->query("SELECT COUNT(*) FROM audit_logs WHERE module_name = 'Governance'")->fetchColumn();
        $this->assertGreaterThan($before, $after);
    }
}
