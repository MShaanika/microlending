<?php

namespace App\Models;

use App\Core\Model;

class Delegation extends Model
{
    public function create(array $data): int
    {
        return $this->insert('delegations', $data);
    }

    public function addScope(int $delegationId, array $scope): int
    {
        $scope['delegation_id'] = $delegationId;
        return $this->insert('delegation_scopes', $scope);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, dr.name AS delegator_name, de.name AS delegate_name, ru.name AS revoked_by_name
             FROM delegations d
             JOIN users dr ON dr.id = d.delegator_user_id
             JOIN users de ON de.id = d.delegate_user_id
             LEFT JOIN users ru ON ru.id = d.revoked_by
             WHERE d.id = ?",
            [$id]
        );
    }

    public function scopesFor(int $delegationId): array
    {
        return $this->all(
            "SELECT s.*, b.branch_name FROM delegation_scopes s LEFT JOIN branches b ON b.id = s.branch_id WHERE s.delegation_id = ?",
            [$delegationId]
        );
    }

    /** @return array{rows: array, total: int, totalPages: int} */
    public function paginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'd.status = ?';
            $params[] = $filters['status'];
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->scalar("SELECT COUNT(*) FROM delegations d" . $whereSql, $params);
        $perPage = max(1, $perPage);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT d.*, dr.name AS delegator_name, de.name AS delegate_name
             FROM delegations d
             JOIN users dr ON dr.id = d.delegator_user_id
             JOIN users de ON de.id = d.delegate_user_id"
            . $whereSql . " ORDER BY d.starts_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))];
    }

    public function revoke(int $id, int $revokedBy, string $reason): void
    {
        $this->update('delegations', [
            'status' => 'Revoked',
            'revoked_by' => $revokedBy,
            'revoked_at' => date('Y-m-d H:i:s'),
            'revoke_reason' => $reason,
        ], 'id', $id);
    }

    /** Every scope's distinct permission_key across every currently-Active delegation for this delegate -- real-time (see DelegationService), not trusting a possibly-stale status alone. */
    public function activePermissionKeysFor(int $delegateUserId): array
    {
        return $this->query(
            "SELECT DISTINCT s.permission_key
             FROM delegations d JOIN delegation_scopes s ON s.delegation_id = d.id
             WHERE d.delegate_user_id = ? AND d.status != 'Revoked' AND NOW() BETWEEN d.starts_at AND d.ends_at",
            [$delegateUserId]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Rows due to transition Scheduled->Active or Active->Expired -- bin/expire_delegations.php's own read, kept here so the query lives with the rest of this model's SQL. */
    public function dueToActivate(): array
    {
        return $this->all("SELECT id FROM delegations WHERE status = 'Scheduled' AND starts_at <= NOW()");
    }

    public function dueToExpire(): array
    {
        return $this->all("SELECT id FROM delegations WHERE status IN ('Scheduled', 'Active') AND ends_at <= NOW()");
    }

    public function markActive(int $id): void
    {
        $this->update('delegations', ['status' => 'Active'], 'id', $id);
    }

    public function markExpired(int $id): void
    {
        $this->update('delegations', ['status' => 'Expired'], 'id', $id);
    }

    /** Permissions it makes sense to delegate -- excludes anything security/permission-administration related, matching Part 13's explicit "never user administration, never security administration, never permission management" examples. */
    public function delegatablePermissions(): array
    {
        return $this->all(
            "SELECT permission_key, permission_name, module_name FROM permissions
             WHERE permission_key NOT LIKE 'admin.%'
               AND permission_key NOT LIKE 'security.%'
               AND permission_key NOT LIKE 'settings.%'
               AND permission_key NOT IN ('delegations.manage', 'feature_flags.manage', 'retention.manage', 'continuity.manage')
             ORDER BY module_name, permission_name"
        );
    }
}
