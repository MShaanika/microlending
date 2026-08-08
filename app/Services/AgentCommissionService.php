<?php

namespace App\Services;

use App\Core\Audit;
use App\Models\AgentCommission;
use App\Models\AgentCommissionEntry;
use App\Models\Company;
use App\Models\Loan;
use App\Models\LoanReschedule;

/**
 * Marketing agent referral commission = a fixed percentage (companies
 * .commission_rate_percent, default 33.33%) of a loan's INTEREST, not
 * principal. Per explicit client rule this is not a single lump-sum
 * trigger: the agent earns their commission proportionally as each
 * installment's interest is actually collected, and any not-yet-earned
 * portion is permanently forfeited if the loan is written off -- nothing
 * already earned from installments actually collected is ever clawed back.
 *
 * Four call sites, one per lifecycle event (see each method's docblock
 * for the exact hook point in the existing codebase):
 *   - onDisbursement()    <- LoanController::release()
 *   - onPaymentAllocated() <- Payment::allocateToSchedule()
 *   - onWriteOff()         <- LoanWriteOffController::post()
 *
 * Eligibility (see evaluateEligibility()) is the client's signed-off
 * "System Rules for Commission" -- commission is for genuine new business
 * only, never for a client who already has another loan running, has a
 * prior default or restructure on file, or hasn't cleared the 30-day
 * cooling-off period since their last loan. A referral that fails any of
 * these still gets an agent_commissions row (so the agent can see why),
 * just at $0 and status 'Ineligible' rather than silently not appearing.
 */
class AgentCommissionService
{
    private const COOLING_PERIOD_DAYS = 30;

    /** Any of these on another of the borrower's loans blocks commission outright. */
    private const ACTIVE_LOAN_STATUSES = ['Draft', 'Pending Approval', 'Approved', 'Released', 'Active', 'Current'];

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

        [$eligible, $reason] = self::evaluateEligibility($loan);

        $commissionId = $commissions->create([
            'loan_id' => $loan['id'],
            'agent_employee_id' => $loan['agent_id'],
            'borrower_id' => $loan['borrower_id'],
            'total_interest_amount' => $interest,
            'commission_rate' => $rate,
            'total_commission_amount' => $eligible ? $total : 0,
            'earned_amount' => 0,
            'paid_amount' => 0,
            'forfeited_amount' => 0,
            'status' => $eligible ? 'Pending' : 'Ineligible',
            'ineligibility_reason' => $reason,
        ]);

        Audit::log(
            'Create',
            'Commissions',
            $eligible
                ? 'Commission #' . $commissionId . ' opened for loan ' . $loan['loan_no'] . ' (' . format_money($total) . ' pending first repayment).'
                : 'Commission for loan ' . $loan['loan_no'] . ' set to $0.00 -- ' . $reason . '.'
        );
    }

    /**
     * Client's "System Rules for Commission", checked in the documented
     * order (an earlier failure short-circuits later ones -- e.g. a
     * client with both an active loan and a past default is reported as
     * "Active Loan", the first rule they trip):
     *   1/2. Existing client with any other non-final loan -> ineligible.
     *   3. Any prior loan of theirs was ever written off -> ineligible,
     *      permanently (no expiry on default history).
     *   4. Any prior loan of theirs was ever restructured -> ineligible,
     *      permanently.
     *   5. Their most recent prior loan (now fully paid) started less
     *      than 30 days before this one -> ineligible until it ages out.
     * A brand new client (no other loans at all) clears all of the above
     * automatically -- that's the "New Business" case.
     *
     * Also guards against a Top-up loan somehow carrying an agent_id
     * (structurally rare -- see LoanController::store()'s comment on
     * agent_id only auto-copying from the introducing application -- but
     * cheap to check directly rather than rely on that alone).
     *
     * @return array{0: bool, 1: ?string} [eligible, ineligibility reason]
     */
    private static function evaluateEligibility(array $loan): array
    {
        if (($loan['loan_type'] ?? 'New Loan') !== 'New Loan') {
            return [false, 'Ineligible \u{2013} Top-up/Renewal Loan'];
        }

        $loans = new Loan();
        $otherLoans = array_filter(
            $loans->forBorrower((int) $loan['borrower_id']),
            fn ($l) => (int) $l['id'] !== (int) $loan['id']
        );

        foreach ($otherLoans as $other) {
            if (in_array($other['loan_status'], self::ACTIVE_LOAN_STATUSES, true)) {
                return [false, 'Ineligible \u{2013} Active Loan'];
            }
        }

        foreach ($otherLoans as $other) {
            if ($other['loan_status'] === 'Written Off') {
                return [false, 'Ineligible \u{2013} Default History'];
            }
        }

        $reschedules = new LoanReschedule();
        foreach ($otherLoans as $other) {
            if ($reschedules->hasImplementedReschedule((int) $other['id'])) {
                return [false, 'Ineligible \u{2013} Restructured Loan'];
            }
        }

        $completedLoans = array_values(array_filter($otherLoans, fn ($l) => $l['loan_status'] === 'Completed'));
        if (!empty($completedLoans)) {
            $lastStartDate = max(array_column($completedLoans, 'start_date'));
            $daysSinceLast = (strtotime((string) $loan['start_date']) - strtotime($lastStartDate)) / 86400;
            if ($daysSinceLast < self::COOLING_PERIOD_DAYS) {
                return [false, 'Ineligible \u{2013} Cooling Period Not Met'];
            }
        }

        return [true, null];
    }

    /**
     * Called from Payment::allocateToSchedule() after $totals['interest']
     * (the interest this specific payment collected, across however many
     * installments it touched) is known. $interestCollected is that
     * per-payment figure, not the loan's running total. A no-op if this
     * payment collected no interest (e.g. it only cleared principal/
     * penalty on an installment whose interest was already settled), or
     * the loan has no agent, or its commission isn't in an accruable
     * state (Ineligible / Fully Earned / Forfeited). 'Pending' is
     * accruable -- this is exactly the "held until first repayment"
     * transition the client's rules call for, and this first payment is
     * what flips it to 'Accruing' (or straight to 'Fully Earned' if it
     * happens to be enough on its own).
     */
    public static function onPaymentAllocated(array $loan, int $paymentId, float $interestCollected, ?int $userId): void
    {
        if ($interestCollected <= 0) {
            return;
        }

        $commissions = new AgentCommission();
        $commission = $commissions->findByLoanId((int) $loan['id']);
        if (!$commission || !in_array($commission['status'], ['Pending', 'Accruing'], true)) {
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

        $newStatus = $newEarned >= $totalCommission ? 'Fully Earned' : 'Accruing';
        $commissions->updateRecord((int) $commission['id'], [
            'earned_amount' => $newEarned,
            'status' => $newStatus,
        ]);

        Audit::log('Update', 'Commissions', 'Commission #' . $commission['id'] . ' earned ' . format_money($increment) . ' from loan ' . $loan['loan_no'] . '\'s payment #' . $paymentId . ' (now ' . $newStatus . ').');
    }

    /**
     * Called right after a write-off posts and the loan's loan_status
     * flips to 'Written Off' (LoanWriteOffController::post()). Forfeits
     * whatever hasn't been earned yet -- including the entire amount if
     * the loan defaulted before a single payment was ever made (still
     * 'Pending') -- while already-earned amounts (from installments
     * actually collected before the default) are untouched and remain
     * payable. Matches the client's rules exactly: "all outstanding
     * commission ... automatically forfeited" but "commission already
     * paid to the agent ... shall not be reversed."
     */
    public static function onWriteOff(int $loanId, ?int $userId): void
    {
        $commissions = new AgentCommission();
        $commission = $commissions->findByLoanId($loanId);
        if (!$commission || !in_array($commission['status'], ['Pending', 'Accruing'], true)) {
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

        Audit::log('Forfeit', 'Commissions', 'Commission #' . $commission['id'] . ' forfeited ' . format_money($remaining) . ' on write-off of loan #' . $loanId . '.');
    }
}
