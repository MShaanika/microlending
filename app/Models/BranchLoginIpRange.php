<?php

namespace App\Models;

use App\Core\Model;

class BranchLoginIpRange extends Model
{
    public function forBranch(int $branchId): array
    {
        return $this->query(
            "SELECT * FROM branch_login_ip_ranges WHERE branch_id = ? ORDER BY id",
            [$branchId]
        )->fetchAll();
    }

    /** All branches with their configured ranges attached -- feeds the admin management screen. */
    public function allBranchesWithRanges(): array
    {
        $branches = $this->query("SELECT * FROM branches WHERE is_active = 1 ORDER BY branch_name")->fetchAll();
        $ranges = $this->query("SELECT * FROM branch_login_ip_ranges ORDER BY id")->fetchAll();

        $byBranch = [];
        foreach ($ranges as $range) {
            $byBranch[(int) $range['branch_id']][] = $range;
        }

        foreach ($branches as &$branch) {
            $branch['ranges'] = $byBranch[(int) $branch['id']] ?? [];
        }

        return $branches;
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM branch_login_ip_ranges WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('branch_login_ip_ranges', $data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM branch_login_ip_ranges WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
