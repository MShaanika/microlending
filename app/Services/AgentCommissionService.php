<?php

namespace App\Services;

use App\Models\AgentCommission;
use App\Models\AgentCommissionEntry;
use App\Models\Company;

/**
 * Marketing agent referral commission = a fixed percentage (companies
 * .commission_rate_percent, default 33.33%) of a loan's INTEREST, not
 * principal. Per explicit client rule this is not a single lump-sum
 * trigger: the agent earns their commission proportionally as each
 * installment's interest is actually collected, and any not-yet-earned
 * portion is permanently forfeited if the loan is written off -- nothing
 * already earned from installments actually collected is ever clawed back.
 *
 * Three call sites, one per lifecycle event (see each method's docblock
 * for the exact hook point in the existing codebase):
 *   - onDisbursement()    <- LoanController::release()
 *   - onPaymentAllocated() <- Payment::allocateToSchedule()
 *   - onWriteOff()         <- LoanWriteOffController::post()
 */
class AgentCommissionService
{
    /**
     * Called right after a loan's loan_status flips to 'Active'
     * (LoanController::release()). A no-op unless the loan has an
     * agent_id (only loans introduced by a marketing agent accrue
     * commission) or a commission row already exists for it (idempotent
     * against an accidental double-call).
     */
    public static function onDisbursement(array $loan, ?int $userId): void
    {
        if (empty($loan['agent_id'])) {
            return;
        }

        $commissions = new AgentCommission();
        if ($commissions->findByLoanId((int) $loan['id'])) {
            return;
        }

        $rate = (float) ((new Company())->primary()['commission_rate_percent'] ?? 33.33);
        $interest = (float) $loan['interest_amount'];
        $total = round($interest * $rate / 100, 2);

        $commissions->create([
            'loan_id' => $loan['id'],
            'agent_employee_id' => $loan['agent_id'],
            'borrower_id' => $loan['borrower_id'],
            'total_interest_amount' => $interest,
            'commission_rate' => $rate,
            'total_commission_amount' => $total,
            'earned_amount' => 0,
            'paid_amount' => 0,
            'forfeited_amount' => 0,
            'status' => 'Accruing',
        ]);
    }

    /**
     * Called from Payment::allocateToSchedule() after $totals['interest']
     * (the interest this specific payment collected, across however many
     * installments it touched) is known. $interestCollected is that
     * per-payment figure, not the loan's running total. A no-op if this
     * payment collected no interest (e.g. it only cleared principal/
     * penalty on an installment whose interest was already settled), or
     * the loan has no agent, or its commission already finished accruing
     * (Fully Earned / Forfeited).
     */
    public static function onPaymentAllocated(array $loan, int $paymentId, float $interestCollected, ?int $userId): void
    {
        if ($interestCollected <= 0) {
            return;
        }

        $commissions = new AgentCommission();
        $commission = $commissions->findByLoanId((int) $loan['id']);
        if (!$commission || $commission['status'] !== 'Accruing') {
            return;
        }

        $rate = (float) $commission['commission_rate'];
        $totalCommission = (float) $commission['total_commission_amount'];
        $earned = (float) $commission['earned_amount'];

        $increment = round($interestCollected * $rate / 100, 2);
        $newEarned = round($earned + $increment, 2);

        // Rounding drift across many small payments should never let the
        // running total overshoot what was promised at disbursement.
        if ($newEarned > $totalCommission) {
            $increment = round($totalCommission - $earned, 2);
            $newEarned = $totalCommission;
        }
        if ($increment <= 0) {
            return;
        }

        (new AgentCommissionEntry())->create([
            'agent_commission_id' => $commission['id'],
            'payment_id' => $paymentId,
            'entry_type' => 'Earned',
            'amount' => $increment,
            'created_by' => $userId,
        ]);

        $commissions->updateRecord((int) $commission['id'], [
            'earned_amount' => $newEarned,
            'status' => $newEarned >= $totalCommission ? 'Fully Earned' : 'Accruing',
        ]);
    }

    /**
     * Called right after a write-off posts and the loan's loan_status
     * flips to 'Written Off' (LoanWriteOffController::post()). Forfeits
     * whatever hasn't been earned yet; already-earned amounts (from
     * installments actually collected before the default) are untouched
     * and remain payable.
     */
    public static function onWriteOff(int $loanId, ?int $userId): void
    {
        $commissions = new AgentCommission();
        $commission = $commissions->findByLoanId($loanId);
        if (!$commission || $commission['status'] !== 'Accruing') {
            return;
        }

        $remaining = round((float) $commission['total_commission_amount'] - (float) $commission['earned_amount'], 2);
        if ($remaining <= 0) {
            return;
        }

        (new AgentCommissionEntry())->create([
            'agent_commission_id' => $commission['id'],
            'payment_id' => null,
            'entry_type' => 'Forfeiture',
            'amount' => $remaining,
            'notes' => 'Loan written off -- unearned commission forfeited.',
            'created_by' => $userId,
        ]);

        $commissions->updateRecord((int) $commission['id'], [
            'forfeited_amount' => $remaining,
            'status' => 'Forfeited',
        ]);
    }
}
