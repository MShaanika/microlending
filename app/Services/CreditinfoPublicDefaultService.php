<?php

namespace App\Services;

use App\Core\Database;
use App\Models\CollectionContact;
use App\Models\CreditinfoDispute;
use App\Models\CreditinfoPublicDefault;
use App\Models\CreditinfoPublicDefaultSetting;

/**
 * Public Defaults business logic -- eligibility review, the dispute hard
 * block, and cost estimation. Deliberately a separate service boundary
 * from App\Services\CreditinfoBureauClient (CBS): this class never calls
 * CreditinfoBureauClient, CreditinfoClient, or anything CBS-specific, and
 * CBS code never calls this class. The two integrations share only
 * infrastructure that was already generic before either of them existed
 * (Encryption, Audit, Auth, Security, Idempotency, the Approval Engine) --
 * never each other's business logic.
 */
class CreditinfoPublicDefaultService
{
    private CreditinfoPublicDefaultSetting $settings;
    private CreditinfoDispute $disputes;
    private CollectionContact $contacts;
    private CreditinfoPublicDefault $defaults;

    public function __construct()
    {
        $this->settings = new CreditinfoPublicDefaultSetting();
        $this->disputes = new CreditinfoDispute();
        $this->contacts = new CollectionContact();
        $this->defaults = new CreditinfoPublicDefault();
    }

    /**
     * Item 5's pre-listing eligibility review. Never invents a legal
     * threshold: a configured minimum is compared against; an unconfigured
     * one is reported as "Requires Compliance Confirmation" and the overall
     * result can never be "Eligible" while any threshold is unconfirmed --
     * only "Provisional" (safe to review, not safe to treat as a cleared
     * business rule) or "Blocked".
     *
     * @return array{
     *   days_in_arrears:int, outstanding_balance:float, last_payment_date:?string,
     *   collection_attempts:int, has_dispute:bool, notice_status:string,
     *   thresholds:array, eligibility_status:string, blocking_reasons:string[]
     * }
     */
    public function evaluateEligibility(array $loan, array $borrower): array
    {
        $outstanding = \App\Services\ArrearsService::loanOutstanding((int) $loan['id'], date('Y-m-d'));
        $lastPayment = $this->lastPaymentDate((int) $loan['id']);
        $collectionAttempts = $this->contacts->countForLoan((int) $loan['id']);
        $hasDispute = $this->disputes->hasOpenDispute((int) $borrower['id']);
        $hasActive = $this->defaults->hasActiveForLoan((int) $loan['id']);

        $minDays = $this->settings->minimumDaysInArrears();
        $minBalance = $this->settings->minimumOutstandingBalance();
        $noticeRequired = $this->settings->borrowerNoticeRequired();
        $waitingDays = $this->settings->noticeWaitingPeriodDays();

        $blockingReasons = [];
        $provisionalReasons = [];

        if ($hasDispute) {
            $blockingReasons[] = 'Credit bureau dispute currently under investigation.';
        }
        if ($hasActive) {
            $blockingReasons[] = 'This loan already has a listing request in progress or an active public default.';
        }

        if ($minDays === null) {
            $provisionalReasons[] = 'Minimum Days in Arrears: Requires Compliance Confirmation.';
        } elseif ($outstanding['days_in_arrears'] < $minDays) {
            $blockingReasons[] = sprintf('Only %d day(s) in arrears; configured minimum is %d.', $outstanding['days_in_arrears'], $minDays);
        }

        if ($minBalance === null) {
            $provisionalReasons[] = 'Minimum Outstanding Balance: Requires Compliance Confirmation.';
        } elseif ($outstanding['outstanding_balance'] < $minBalance) {
            $blockingReasons[] = sprintf('Outstanding balance %.2f is below the configured minimum of %.2f.', $outstanding['outstanding_balance'], $minBalance);
        }

        if ($noticeRequired && $waitingDays === null) {
            $provisionalReasons[] = 'Notice Waiting Period: Requires Compliance Confirmation.';
        }

        $noticeStatus = $noticeRequired
            ? ($waitingDays === null ? 'Required -- waiting period not yet confirmed' : 'Required -- ' . $waitingDays . ' day(s) notice')
            : 'Not required (configurable -- confirm with Compliance)';

        if (!empty($blockingReasons)) {
            $status = 'Blocked';
        } elseif (!empty($provisionalReasons)) {
            $status = 'Provisional -- Requires Compliance Confirmation';
        } else {
            $status = 'Eligible';
        }

        return [
            'days_in_arrears' => (int) $outstanding['days_in_arrears'],
            'outstanding_balance' => (float) $outstanding['outstanding_balance'],
            'last_payment_date' => $lastPayment,
            'collection_attempts' => $collectionAttempts,
            'has_dispute' => $hasDispute,
            'notice_status' => $noticeStatus,
            'thresholds' => [
                'minimum_days_in_arrears' => $minDays,
                'minimum_outstanding_balance' => $minBalance,
                'notice_waiting_period_days' => $waitingDays,
            ],
            'eligibility_status' => $status,
            'blocking_reasons' => $blockingReasons,
            'provisional_reasons' => $provisionalReasons,
        ];
    }

    /** Item 6's hard control -- called again at submission time (store()), never trusted from an eligibility screen the user may have sat on for a while. */
    public function assertNoOpenDispute(int $borrowerId): void
    {
        if ($this->disputes->hasOpenDispute($borrowerId)) {
            throw new \RuntimeException('PUBLIC DEFAULT LISTING BLOCKED — Reason: Credit bureau dispute currently under investigation.');
        }
    }

    private function lastPaymentDate(int $loanId): ?string
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT MAX(payment_date) FROM payments WHERE loan_id = ?");
        $stmt->execute([$loanId]);
        $date = $stmt->fetchColumn();
        return $date ?: null;
    }

    /** Item 15: this calendar month's estimated usage cost from the configured tariff -- never a hardcoded N$15.60 in code. */
    public function usageThisMonth(): array
    {
        $listings = $this->defaults->transactionCountThisMonth('listed_at');
        $removals = $this->defaults->transactionCountThisMonth('removed_at');
        $listingFee = $this->settings->listingFee();
        $removalFee = $this->settings->removalFee();

        return [
            'listings_count' => $listings,
            'listings_cost' => round($listings * $listingFee, 2),
            'removals_count' => $removals,
            'removals_cost' => round($removals * $removalFee, 2),
            'total_transactions' => $listings + $removals,
            'total_cost_excl_vat' => round(($listings * $listingFee) + ($removals * $removalFee), 2),
            'listing_fee' => $listingFee,
            'removal_fee' => $removalFee,
            'vat_rate' => $this->settings->vatRate(),
        ];
    }
}
