<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Payment;

/**
 * Posts ONE successful Collexia collection as payment(s). Both entry points that turn a collection into money -- the API download
 * (CollexiaPaymentReconciliationService) and the report import (DebitOrderCollectionController) -- go through here, so the rules live once:
 *
 *  - an old-style mandate (no split leg): the loan's FIFO waterfall, as always;
 *  - a lane instalment that settles ONE schedule row: that row, on the loan the ROW belongs to -- never assumed to be the debit order's loan,
 *    so a top-up's row riding in the original loan's mandate can no longer be mis-posted as the original's overpayment;
 *  - a COMBINED lane instalment (several rows, original loan + top-ups, one debit): split across those rows, each part posted on its own loan.
 */
class DebitOrderCollectionPoster
{
    private Payment $payments;
    private Loan $loans;
    private CollexiaMandateLookupService $lookup;

    public function __construct(?Payment $payments = null, ?Loan $loans = null, ?CollexiaMandateLookupService $lookup = null)
    {
        $this->payments = $payments ?? new Payment();
        $this->loans = $loans ?? new Loan();
        $this->lookup = $lookup ?? new CollexiaMandateLookupService();
    }

    /**
     * @param array $mandate CollexiaMandateLookupService::resolve()
     * @return ?int the (first) payment id, or null when nothing could be posted (left for review)
     */
    public function post(array $mandate, int $installmentNo, float $amount, array $meta): ?int
    {
        $debitOrderLoan = $this->loans->find((int) $mandate['loan_id']);
        if (!$debitOrderLoan) {
            return null;
        }

        if (($mandate['split_no'] ?? null) === null) {
            return $this->payments->recordAndAllocate($debitOrderLoan, $amount, $meta);
        }

        $parts = $this->lookup->partsFor($mandate, $installmentNo);
        if (count($parts) > 1) {
            $ids = $this->payments->recordCollectionParts($parts, $amount, $meta);
            return $ids === [] ? null : (int) reset($ids);
        }

        $scheduleId = $this->lookup->scheduleIdFor($mandate, $installmentNo);
        if (!$scheduleId) {
            return null; // no mapping for this installment: leave it unposted for review rather than guess
        }
        $row = $this->loans->scheduleRow($scheduleId);
        $loan = $debitOrderLoan;
        if ($row && (int) $row['loan_id'] !== (int) $debitOrderLoan['id']) {
            $rowLoan = $this->loans->find((int) $row['loan_id']);
            // only ever the debit order's own loan or one of its top-ups; any other loan means the mapping is wrong, so nothing is posted
            if (!$rowLoan || (int) ($rowLoan['topup_of_loan_id'] ?? 0) !== (int) $debitOrderLoan['id']) {
                return null;
            }
            $loan = $rowLoan;
        }
        return $this->payments->recordAndAllocateToScheduleId($loan, $scheduleId, $amount, $meta);
    }
}
