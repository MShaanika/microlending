<?php

namespace App\Services;

use App\Core\Database;
use App\Models\PayCyclePolicy;

/**
 * Resolves the effective pay-cycle rule for one borrower, in the
 * explicit precedence order requested:
 *
 *   Borrower Override
 *     -> Employer Pay-Cycle Policy (via borrower_employment.employer_name)
 *     -> Borrower Assigned Policy (manual fallback)
 *     -> no adjustment (existing/default behaviour, unchanged)
 *
 * Employer matching is an exact, admin-curated employer_name lookup
 * (see employer_pay_cycle_policies) -- never inferred from the name
 * (no "contains Ministry" pattern matching).
 */
class PayCycleResolverService
{
    private PayCyclePolicy $policies;

    public function __construct(?PayCyclePolicy $policies = null)
    {
        $this->policies = $policies ?? new PayCyclePolicy();
    }

    /**
     * @return array{normal_pay_day:int, non_business_day_rule:string, pay_cycle_policy_id:?int, source:string}|null
     *         null means: no policy or override applies -- caller must not adjust anything.
     */
    public function resolveFor(int $borrowerId, int $loanPaymentDay): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT b.pay_cycle_policy_id, b.pay_day_override, b.non_business_day_rule_override,
                    (SELECT employer_name FROM borrower_employment be WHERE be.borrower_id = b.id AND be.is_current = 1 ORDER BY be.id DESC LIMIT 1) AS current_employer_name
             FROM borrowers b WHERE b.id = ?"
        );
        $stmt->execute([$borrowerId]);
        $db = $stmt->fetch();

        if (!$db) {
            return null;
        }

        // 1. Borrower override -- either field set is enough to apply an
        // override; a missing pay_day_override falls back to the loan's
        // own payment_day (the override is then only about the rule).
        if ($db['pay_day_override'] !== null || $db['non_business_day_rule_override'] !== null) {
            return [
                'normal_pay_day' => $db['pay_day_override'] !== null ? (int) $db['pay_day_override'] : $loanPaymentDay,
                'non_business_day_rule' => $db['non_business_day_rule_override'] ?? 'NONE',
                'pay_cycle_policy_id' => null,
                'source' => 'BORROWER_OVERRIDE',
            ];
        }

        // 2. Employer-level policy, via exact current employer_name match.
        if (!empty($db['current_employer_name'])) {
            $policy = $this->policies->policyForEmployerName($db['current_employer_name']);
            if ($policy) {
                return [
                    'normal_pay_day' => (int) $policy['normal_pay_day'],
                    'non_business_day_rule' => $policy['non_business_day_rule'],
                    'pay_cycle_policy_id' => (int) $policy['id'],
                    'source' => 'EMPLOYER_POLICY',
                ];
            }
        }

        // 3. Borrower's own manually assigned policy (fallback for an
        // employer not yet mapped).
        if (!empty($db['pay_cycle_policy_id'])) {
            $policy = $this->policies->find((int) $db['pay_cycle_policy_id']);
            if ($policy && $policy['is_active']) {
                return [
                    'normal_pay_day' => (int) $policy['normal_pay_day'],
                    'non_business_day_rule' => $policy['non_business_day_rule'],
                    'pay_cycle_policy_id' => (int) $policy['id'],
                    'source' => 'BORROWER_ASSIGNED_POLICY',
                ];
            }
        }

        // 4. No adjustment -- existing behaviour, unchanged.
        return null;
    }
}
