<?php

namespace App\Models;

use App\Core\Model;

/**
 * Employer-level pay-cycle policy ("Government Payroll": pay day 20,
 * roll to previous business day). Attached to borrowers indirectly via
 * EmployerPayCyclePolicy (employer_name match) or directly via
 * borrowers.pay_cycle_policy_id -- see PayCycleResolverService for the
 * precedence order.
 */
class PayCyclePolicy extends Model
{
    public function allPolicies(bool $activeOnly = false): array
    {
        $sql = "SELECT p.*, cu.name AS created_by_name, uu.name AS updated_by_name,
                       (SELECT COUNT(*) FROM employer_pay_cycle_policies e WHERE e.pay_cycle_policy_id = p.id AND e.is_active = 1) AS employer_count,
                       (SELECT COUNT(*) FROM borrowers b WHERE b.pay_cycle_policy_id = p.id) AS borrower_count
                FROM pay_cycle_policies p
                LEFT JOIN users cu ON cu.id = p.created_by
                LEFT JOIN users uu ON uu.id = p.updated_by";
        if ($activeOnly) {
            $sql .= " WHERE p.is_active = 1";
        }
        $sql .= " ORDER BY p.policy_name";
        return $this->all($sql);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM pay_cycle_policies WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('pay_cycle_policies', $data);
    }

    public function updateRecord(int $id, array $data): void
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $this->query("UPDATE pay_cycle_policies SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    }

    // --- Employer mapping ---

    public function employerMappings(): array
    {
        return $this->all(
            "SELECT e.*, p.policy_name, u.name AS created_by_name
             FROM employer_pay_cycle_policies e
             JOIN pay_cycle_policies p ON p.id = e.pay_cycle_policy_id
             LEFT JOIN users u ON u.id = e.created_by
             ORDER BY e.employer_name"
        );
    }

    /** Distinct employer_name values actually in use, so an admin picks from what staff have typed rather than free-typing a new (possibly mismatched) string. */
    public function distinctEmployerNamesInUse(): array
    {
        $rows = $this->all(
            "SELECT DISTINCT employer_name FROM borrower_employment WHERE employer_name IS NOT NULL AND employer_name <> '' AND is_current = 1 ORDER BY employer_name"
        );
        return array_column($rows, 'employer_name');
    }

    public function mapEmployer(string $employerName, int $policyId, ?int $userId): int
    {
        return $this->insert('employer_pay_cycle_policies', [
            'employer_name' => $employerName,
            'pay_cycle_policy_id' => $policyId,
            'created_by' => $userId,
        ]);
    }

    public function setMappingActive(int $id, bool $active): void
    {
        $this->query("UPDATE employer_pay_cycle_policies SET is_active = ? WHERE id = ?", [$active ? 1 : 0, $id]);
    }

    /** The one lookup PayCycleResolverService actually calls: does this exact (current) employer_name have an active mapping? */
    public function policyForEmployerName(string $employerName): ?array
    {
        return $this->one(
            "SELECT p.* FROM employer_pay_cycle_policies e
             JOIN pay_cycle_policies p ON p.id = e.pay_cycle_policy_id
             WHERE e.employer_name = ? AND e.is_active = 1 AND p.is_active = 1",
            [$employerName]
        );
    }
}
