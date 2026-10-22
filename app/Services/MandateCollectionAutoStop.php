<?php

namespace App\Services;

use App\Core\Audit;
use App\Models\CollexiaSetting;
use App\Models\Loan;

/**
 * Runs right after staff record a payment: if it cleared an instalment that a live Collexia mandate is still set to collect, that
 * collection is cancelled AUTOMATICALLY and the person recording the payment is told what happened -- nobody has to remember.
 *
 * It uses exactly the same checks and cancel as the "Stop this collection" button (MandateCollectionCheck + CollexiaCollectionStopper),
 * so it cancels only what that button would: an installment that is fully paid, still Active at Collexia, with a collection date that is
 * not today or tomorrow. Whatever it cannot stop is reported in plain words and stays on the red notice with its button.
 *
 * Never lets a problem reach the payment itself -- the payment is already saved before this runs, and any failure here is returned as a
 * message, not thrown. Every cancel (and every refusal) is written to the audit trail and the loan's own history, marked as automatic.
 *
 * Can be switched off in the Collexia settings (CollexiaSetting::autoStopCollections()); then the notice and button work as before.
 */
class MandateCollectionAutoStop
{
    private MandateCollectionCheck $check;
    private CollexiaCollectionStopper $stopper;
    private CollexiaSetting $settings;
    private Loan $loans;

    public function __construct(?MandateCollectionCheck $check = null, ?CollexiaCollectionStopper $stopper = null, ?CollexiaSetting $settings = null, ?Loan $loans = null)
    {
        $this->check = $check ?? new MandateCollectionCheck();
        $this->stopper = $stopper ?? new CollexiaCollectionStopper();
        $this->settings = $settings ?? new CollexiaSetting();
        $this->loans = $loans ?? new Loan();
    }

    /**
     * @return array{attempted: bool, cancelled: int, messages: string[], problems: string[]}
     *   messages = what was cancelled; problems = what could not be (shown as a warning)
     */
    public function afterPayment(int $loanId, ?int $userId, string $frontEndUserName, ?string $today = null): array
    {
        $out = ['attempted' => false, 'cancelled' => 0, 'messages' => [], 'problems' => []];
        $today = $today ?? date('Y-m-d');

        if (!$this->settings->autoStopCollections() || !$this->settings->isEnabled()) {
            return $out;
        }
        $check = $this->check->forLoan($loanId, $today);
        $mandate = $check['mandate'];
        if (!$mandate || (!$check['paidButScheduled'] && !$check['surplus'])) {
            return $out;
        }
        $loan = $this->loans->find($loanId);
        $fmt = fn ($d) => date('d M Y', strtotime($d));

        if (!$check['canStop']) {
            $out['problems'][] = 'This loan has a live debit order that is still set to collect what you just paid, but it was not placed through the Collexia API, so it could not be stopped automatically -- ask Collexia to cancel it.';
            return $out;
        }

        // Lane mandate: each already-paid installment, once.
        $done = [];
        foreach ($check['paidButScheduled'] as $p) {
            $sid = (int) $p['schedule_id'];
            if (isset($done[$sid])) {
                continue;
            }
            $done[$sid] = true;

            if (!empty($p['imminent'])) {
                $out['problems'][] = 'Installment #' . (int) $p['installment_no'] . ' is already paid but mandate ' . (int) $p['mandate'] . ' is due to collect ' . format_money($p['amount']) . ' on ' . $fmt($p['collects_on'])
                    . ' and that can no longer be stopped. If the borrower is debited, refund the overpayment.';
                $this->record($loan, $mandate, $userId, 'Not stopped (due too soon): installment #' . (int) $p['installment_no'] . ' collects ' . $fmt($p['collects_on']) . '.', 0, $sid);
                continue;
            }

            $out['attempted'] = true;
            $r = $this->stopper->stopInstallment($mandate, $sid, $frontEndUserName, $today);
            $out['cancelled'] += $r['cancelled'];
            if ($r['cancelled'] > 0) {
                $out['messages'][] = 'Installment #' . (int) $p['installment_no'] . ' was paid, so its ' . format_money($p['amount']) . ' collection on ' . $fmt($p['collects_on']) . ' (mandate ' . (int) $p['mandate'] . ') was cancelled automatically at Collexia.';
            } else {
                $out['problems'][] = 'Installment #' . (int) $p['installment_no'] . ' is paid but its ' . $fmt($p['collects_on']) . ' collection could not be cancelled automatically: ' . trim(implode(' ', $r['messages'])) . ' Use the red notice on the loan to try again.';
            }
            $this->record($loan, $mandate, $userId, trim(implode(' ', $r['messages'])), $r['cancelled'], $sid);
        }

        // Single recurring mandate: the surplus collections at the end.
        if ($check['surplus']) {
            $s = $check['surplus'];
            if ($s['stoppable'] > 0) {
                $out['attempted'] = true;
                $r = $this->stopper->stopSurplus($mandate, $frontEndUserName, $today);
                $out['cancelled'] += $r['cancelled'];
                if ($r['cancelled'] > 0) {
                    $out['messages'][] = 'The recurring mandate was set to collect more than the loan now needs, so ' . $r['cancelled'] . ' surplus collection' . ($r['cancelled'] === 1 ? '' : 's')
                        . ' at the end ' . ($r['cancelled'] === 1 ? 'was' : 'were') . ' cancelled automatically at Collexia.';
                } else {
                    $out['problems'][] = 'The recurring mandate will over-collect about ' . format_money($s['amount']) . ' and it could not be reduced automatically: ' . trim(implode(' ', $r['messages'])) . ' Use the red notice on the loan to try again.';
                }
                $this->record($loan, $mandate, $userId, trim(implode(' ', $r['messages'])), $r['cancelled'], null);
            }
            if ($s['stoppable'] < $s['count']) {
                $out['problems'][] = ($s['count'] - $s['stoppable']) . ' surplus collection(s) on the recurring mandate are due today or tomorrow and can no longer be stopped. If the borrower is debited, refund the overpayment.';
            }
        }

        return $out;
    }

    private function record(?array $loan, array $mandate, ?int $userId, string $detail, int $cancelled, ?int $scheduleId): void
    {
        if (!$loan) {
            return;
        }
        $summary = 'AUTOMATIC stop of a collection after a manual payment on debit order ' . $mandate['debit_order_no'] . ': '
            . ($cancelled > 0 ? $cancelled . ' cancelled' : 'nothing cancelled') . '. ' . $detail;
        Audit::log('Update', 'Debit Orders', $summary, [
            'loan_id' => (int) $loan['id'], 'debit_order_id' => (int) $mandate['id'], 'schedule_id' => $scheduleId,
            'event' => 'auto_stop_collection_after_manual_payment', 'cancelled' => $cancelled,
        ]);
        $this->loans->logStatus((int) $loan['id'], $loan['loan_status'], $loan['loan_status'], $userId, substr($summary, 0, 1000));
    }
}
