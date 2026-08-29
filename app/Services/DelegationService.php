<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Events;
use App\Models\Delegation;

/**
 * Secure temporary delegation (Part 12-15) -- deliberately separate from
 * Auth::startImpersonation() ("Login As"), which swaps the acting
 * user's session identity entirely. A delegation never does that: the
 * delegate stays logged in as themselves, and DelegationService is
 * consulted only at the moment they try to use a delegated permission
 * (see activeDelegationGranting(), called from ApprovalService).
 *
 * No org-hierarchy exists in this app (no reports_to/manager_id column
 * anywhere -- see the Phase 0 audit), so delegations are always
 * explicit delegator/delegate pairs an admin configures, never inferred
 * from a management chain.
 */
class DelegationService
{
    /**
     * Real-time check: never trusts delegations.status alone (it's a
     * display/audit convenience kept current by bin/expire_delegations.php,
     * not the source of truth) -- always re-verifies starts_at/ends_at and
     * that the delegation hasn't been revoked, at the exact moment of use.
     *
     * @return array{id:int, delegator_user_id:int, delegator_name:string, amount_limit:?float}|null
     */
    public static function activeDelegationGranting(int $delegateUserId, string $permissionKey, float $amount = 0): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT d.id, d.delegator_user_id, u.name AS delegator_name, s.amount_limit
             FROM delegations d
             JOIN delegation_scopes s ON s.delegation_id = d.id
             JOIN users u ON u.id = d.delegator_user_id
             WHERE d.delegate_user_id = ?
               AND d.status != 'Revoked'
               AND NOW() BETWEEN d.starts_at AND d.ends_at
               AND s.permission_key = ?
             LIMIT 1"
        );
        $stmt->execute([$delegateUserId, $permissionKey]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['amount_limit'] !== null && $amount > (float) $row['amount_limit']) {
            return null; // scoped, but the amount exceeds what this delegation was limited to
        }
        return $row;
    }

    /** Independent of session -- checks an arbitrary user's actual role-granted permissions, used to enforce Part 14's "never allow authority beyond the delegator's own authority" when a delegation is created. */
    public static function userHasPermission(int $userId, string $permissionKey): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ? AND p.permission_key = ?"
        );
        $stmt->execute([$userId, $permissionKey]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array $scopes Each: ['permission_key' => string, 'module' => ?string, 'amount_limit' => ?float, 'branch_id' => ?int]
     * @throws \RuntimeException if the delegator doesn't actually hold a scoped permission, or the delegate is the delegator.
     */
    public static function create(int $delegatorUserId, int $delegateUserId, string $startsAt, string $endsAt, ?string $reason, array $scopes, int $createdBy): int
    {
        if ($delegatorUserId === $delegateUserId) {
            throw new \RuntimeException('A delegator cannot delegate to themselves.');
        }
        if (empty($scopes)) {
            throw new \RuntimeException('A delegation must grant at least one specific permission -- never a blanket copy of the delegator\'s role.');
        }
        foreach ($scopes as $scope) {
            if (!self::userHasPermission($delegatorUserId, $scope['permission_key'])) {
                throw new \RuntimeException("The delegator does not hold '{$scope['permission_key']}' themselves -- authority cannot extend beyond what they actually have.");
            }
        }

        $model = new Delegation();
        $delegationId = $model->create([
            'delegator_user_id' => $delegatorUserId,
            'delegate_user_id' => $delegateUserId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $reason,
            'status' => strtotime($startsAt) <= time() ? 'Active' : 'Scheduled',
            'created_by' => $createdBy,
        ]);

        foreach ($scopes as $scope) {
            $model->addScope($delegationId, $scope);
        }

        $conflicts = SegregationOfDutyService::conflictsIntroducedByDelegation($delegateUserId, array_column($scopes, 'permission_key'));

        Audit::log('Create', 'Governance', "Created delegation from user #$delegatorUserId to user #$delegateUserId" . ($conflicts ? ' (segregation-of-duty conflict flagged)' : ''), [
            'delegation_id' => $delegationId,
            'scopes' => array_column($scopes, 'permission_key'),
            'sod_conflicts' => $conflicts,
        ]);
        Events::fire('DelegationActivated', ['delegation_id' => $delegationId]);

        return $delegationId;
    }

    public static function revoke(int $delegationId, int $revokedBy, string $reason): void
    {
        (new Delegation())->revoke($delegationId, $revokedBy, $reason);
        Audit::log('Revoke', 'Governance', "Revoked delegation #$delegationId: $reason", ['delegation_id' => $delegationId]);
        Events::fire('DelegationExpired', ['delegation_id' => $delegationId, 'reason' => 'revoked']);
    }
}
