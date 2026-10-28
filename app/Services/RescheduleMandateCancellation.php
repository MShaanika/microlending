<?php

namespace App\Services;

use App\Models\CollexiaSetting;
use App\Models\DebitOrder;

/**
 * When a reschedule is implemented on a loan whose debit order is already placed at Collexia, that mandate would go on collecting the OLD
 * dates and amounts. So it is cancelled AUTOMATICALLY, right then, with no manual step -- and the person implementing is told exactly what
 * happened. It is the same cancel, with the same audit entry and loan-history line, as the guided Replace page
 * (MandateReplacementService::cancelForReplacement), recorded as automatic and tied to the reschedule.
 *
 * If Collexia refuses, nothing is lost: the debit order is already flagged out of step, so the red notice and the Replace button stay on the
 * loan until it is done by hand. A collection due today or tomorrow is on its way to the bank and cannot be recalled by cancelling the
 * mandate; that is reported so the borrower can be refunded if the old amount is debited.
 *
 * Never throws -- the reschedule is already implemented and must not be undone by a problem here. Switchable in the Collexia settings
 * (CollexiaSetting::autoCancelOnReschedule(), on by default); with it off the red notice and Replace button work as before.
 */
class RescheduleMandateCancellation
{
    private MandateReplacementService $replacement;
    private CollexiaSetting $settings;
    private MandateCollectionCheck $check;
    private DebitOrder $debitOrders;

    public function __construct(?MandateReplacementService $replacement = null, ?CollexiaSetting $settings = null, ?MandateCollectionCheck $check = null, ?DebitOrder $debitOrders = null)
    {
        $this->replacement = $replacement ?? new MandateReplacementService();
        $this->settings = $settings ?? new CollexiaSetting();
        $this->check = $check ?? new MandateCollectionCheck();
        $this->debitOrders = $debitOrders ?? new DebitOrder();
    }

    /**
     * @param array $reschedule the implemented reschedule (needs loan_id, reschedule_no)
     * @param array<int, array> $liveDebitOrders the loan's live debit orders at the moment it was implemented
     * @return array{attempted: bool, cancelled: string[], failed: string[], warnings: string[]}
     */
    public function afterReschedule(array $reschedule, array $liveDebitOrders, ?int $userId, ?string $today = null): array
    {
        $out = ['attempted' => false, 'cancelled' => [], 'failed' => [], 'warnings' => []];
        if (!$liveDebitOrders || !$this->settings->autoCancelOnReschedule()) {
            return $out;
        }

        // Read BEFORE cancelling: afterwards the mandate is no longer live and there is nothing left to read.
        $imminent = [];
        try {
            $check = $this->check->forLoan((int) $reschedule['loan_id'], $today);
            $imminent = array_values(array_filter($check['upcoming'], fn ($u) => !empty($u['imminent'])));
        } catch (\Throwable $e) {
            // Only used for a warning; the cancel itself does not depend on it.
        }

        $reason = $reschedule['cancel_reason'] ?? 'Automatic: the loan was rescheduled (' . $reschedule['reschedule_no'] . '), so this mandate -- which would keep collecting the old dates and amounts -- was cancelled so a new one can be registered from the new schedule.';
        foreach ($liveDebitOrders as $debitOrder) {
            $out['attempted'] = true;
            $label = (string) $debitOrder['debit_order_no'];
            try {
                $fresh = $this->debitOrders->find((int) $debitOrder['id']) ?? $debitOrder;
                $result = $this->replacement->cancelForReplacement($fresh, $reason, $userId);
            } catch (\Throwable $e) {
                $result = ['success' => false, 'message' => $e->getMessage()];
            }
            if ($result['success']) {
                $out['cancelled'][] = $label;
            } else {
                $out['failed'][] = $label . ': ' . $result['message'];
            }
        }

        if ($out['cancelled'] && $imminent) {
            $dates = array_map(fn ($u) => format_money($u['amount']) . ' on ' . date('d M Y', strtotime($u['collects_on'])), $imminent);
            $out['warnings'][] = 'A collection due too soon to be stopped (' . implode(', ', $dates) . ') may still be debited at the OLD amount -- if it is, refund the difference.';
        }
        return $out;
    }
}
