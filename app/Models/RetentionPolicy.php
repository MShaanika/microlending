<?php

namespace App\Models;

use App\Core\Model;

class RetentionPolicy extends Model
{
    public function allPolicies(): array
    {
        return $this->all('SELECT p.*, u.name AS updated_by_name FROM retention_policies p LEFT JOIN users u ON u.id = p.updated_by ORDER BY p.policy_name');
    }

    public function activePolicies(): array
    {
        return $this->all('SELECT * FROM retention_policies WHERE is_active = 1');
    }

    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM retention_policies WHERE id = ?', [$id]);
    }

    public function updatePolicy(int $id, array $data): void
    {
        $this->update('retention_policies', $data, 'id', $id);
    }

    public function recordRun(int $policyId, bool $dryRun, int $eligible, int $held, int $deleted, ?int $ranBy): void
    {
        $this->insert('retention_runs', [
            'policy_id' => $policyId,
            'dry_run' => $dryRun ? 1 : 0,
            'eligible_count' => $eligible,
            'held_count' => $held,
            'deleted_count' => $deleted,
            'ran_by' => $ranBy,
        ]);
    }

    public function recentRuns(int $policyId, int $limit = 10): array
    {
        return $this->all(
            "SELECT r.*, u.name AS ran_by_name FROM retention_runs r LEFT JOIN users u ON u.id = r.ran_by WHERE r.policy_id = ? ORDER BY r.ran_at DESC LIMIT " . max(1, $limit),
            [$policyId]
        );
    }

    // -- Legal holds --

    public function activeHold(string $table, int $resourceId): ?array
    {
        return $this->one(
            "SELECT * FROM legal_holds WHERE resource_table = ? AND resource_id = ? AND is_active = 1",
            [$table, $resourceId]
        );
    }

    public function placeHold(string $table, int $resourceId, string $reason, int $placedBy): int
    {
        return $this->insert('legal_holds', [
            'resource_table' => $table,
            'resource_id' => $resourceId,
            'reason' => $reason,
            'placed_by' => $placedBy,
        ]);
    }

    public function releaseHold(int $holdId, int $releasedBy, string $reason): void
    {
        $this->update('legal_holds', [
            'is_active' => 0,
            'released_by' => $releasedBy,
            'released_at' => date('Y-m-d H:i:s'),
            'release_reason' => $reason,
        ], 'id', $holdId);
    }

    public function activeHoldsFor(string $table): array
    {
        return $this->query('SELECT resource_id FROM legal_holds WHERE resource_table = ? AND is_active = 1', [$table])
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function allHolds(): array
    {
        return $this->all(
            "SELECT h.*, u.name AS placed_by_name, ru.name AS released_by_name
             FROM legal_holds h
             LEFT JOIN users u ON u.id = h.placed_by
             LEFT JOIN users ru ON ru.id = h.released_by
             ORDER BY h.placed_at DESC"
        );
    }
}
