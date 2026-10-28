<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Session;
use App\Models\DebitOrder;
use App\Models\Loan;

/**
 * When a top-up is released, reversed or consolidated -- or a top-up is rescheduled -- what the original loan's live Collexia debit order
 * will collect stops matching what the family now owes. A mandate's amounts are fixed when it is placed, so exactly as for a reschedule:
 * the live debit order is flagged OUT OF STEP (red notice + Replace button) and, unless switched off, cancelled automatically so staff
 * register ONE new combined debit order from the new schedule. Never throws: the change itself is already saved.
 */
class TopUpMandateAdjustment
{
    private DebitOrder $debitOrders;
    private Loan $loans;
    private RescheduleMandateCancellation $canceller;

    public function __construct(?DebitOrder $debitOrders = null, ?Loan $loans = null, ?RescheduleMandateCancellation $canceller = null)
    {
        $this->debitOrders = $debitOrders ?? new DebitOrder();
        $this->loans = $loans ?? new Loan();
        $this->canceller = $canceller ?? new RescheduleMandateCancellation();
    }

    /**
     * @param int $anyLoanId the original loan, or any of its top-ups
     * @param string $what what happened, in words ("top-up LN-... was released")
     * @return string a sentence to add to the success message ('' when no debit order is live)
     */
    public function afterTopUpChange(int $anyLoanId, string $what, ?int $userId): string
    {
        try {
            $loan = $this->loans->find($anyLoanId);
            if (!$loan) {
                return '';
            }
            $originalId = (int) ($loan['topup_of_loan_id'] ?: $loan['id']);
            $original = $originalId === (int) $loan['id'] ? $loan : $this->loans->find($originalId);
            if (!$original) {
                return '';
            }

            $note = 'A top-up changed this loan after this debit order was registered (' . $what . '); its mandate still collects the old amounts.';
            $stale = $this->debitOrders->markMandatesOutOfStep($originalId, null, $note);
            if (!$stale) {
                return '';
            }
            foreach ($stale as $debitOrder) {
                Audit::log('Update', 'Debit Orders', 'Debit order ' . $debitOrder['debit_order_no'] . ' is now OUT OF STEP with loan ' . $original['loan_no'] . ': ' . $what . ' while its mandate is live at Collexia.', [
                    'loan_id' => $originalId, 'debit_order_id' => (int) $debitOrder['id'], 'event' => 'mandate_out_of_step',
                ]);
                $this->loans->logStatus($originalId, $original['loan_status'] ?? null, $original['loan_status'] ?? null, $userId,
                    'Debit order ' . $debitOrder['debit_order_no'] . ' is still live at Collexia and no longer matches the loan and its top-ups (' . $what . '). It needs to be replaced by one combined debit order.');
            }

            $reason = 'Automatic: ' . $what . ', so this mandate -- which would keep collecting the old amounts -- was cancelled so ONE new combined debit order can be registered on the original loan.';
            $auto = $this->canceller->afterReschedule(['loan_id' => $originalId, 'reschedule_no' => '', 'cancel_reason' => $reason], $stale, $userId);

            $text = '';
            if ($auto['cancelled']) {
                $text .= ' The original loan\'s old debit order (' . implode(', ', $auto['cancelled']) . ') was cancelled at Collexia automatically, because it would have kept collecting the old amounts. Register ONE new combined debit order on ' . $original['loan_no'] . '.';
            }
            if ($auto['failed']) {
                Session::flash('error', 'The old debit order could NOT be cancelled automatically (' . implode('; ', $auto['failed']) . '). It is flagged on the loan -- use "Replace mandate" there to finish it.');
            } elseif (!$auto['attempted']) {
                $text .= ' ' . $original['loan_no'] . ' has a live debit order that no longer matches its top-ups -- replace it from the red notice on the loan or the debit order.';
            }
            foreach ($auto['warnings'] as $warning) {
                $text .= ' ' . $warning;
            }
            return $text;
        } catch (\Throwable $e) {
            error_log('TopUpMandateAdjustment: ' . $e->getMessage());
            return '';
        }
    }
}
