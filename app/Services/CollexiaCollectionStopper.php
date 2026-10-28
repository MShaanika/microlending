<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLegInstallment;
use App\Models\Loan;

/**
 * Stops collections at Collexia that a manual payment has made unnecessary. Never automatic: it runs only from the "Stop this
 * collection" button, after a confirmation, and everything it relies on is re-checked at that moment -- against the database and
 * against what Collexia says it is holding right now -- because the notice the button sits in may be hours old.
 *
 * It only ever cancels an installment when:
 *   - the loan installment it settles is STILL fully paid (lane mandate), or the mandate really is collecting more than the loan owes
 *     (single recurring mandate);
 *   - Collexia still holds it as Active and its collection date is beyond MandateCollectionCheck::IMMINENT_DAYS -- a collection due
 *     today or tomorrow is on its way to the bank and cannot be recalled;
 *   - for a lane, the mandate is Registered and every one of its installments has been set to its planned date, so Collexia's installment
 *     numbers are known to line up with ours.
 *
 * It reuses CollexiaEndoApiClient::cancelInstallment() -- the same call as the Cancel Installment button and the paid-by-hand cancel.
 */
class CollexiaCollectionStopper
{
    /** @var callable returns an object with installmentRequest()/cancelInstallment() -- the real client unless a test injects one */
    private $clientFactory;
    private DebitOrderSplitLegInstallment $lane;
    private Loan $loans;
    private DebitOrder $debitOrders;

    public function __construct(?callable $clientFactory = null, ?DebitOrderSplitLegInstallment $lane = null, ?Loan $loans = null, ?DebitOrder $debitOrders = null)
    {
        $this->clientFactory = $clientFactory ?? fn () => new CollexiaEndoApiClient();
        $this->lane = $lane ?? new DebitOrderSplitLegInstallment();
        $this->loans = $loans ?? new Loan();
        $this->debitOrders = $debitOrders ?? new DebitOrder();
    }

    /**
     * Lane (or one-off split) mandate: stop the collection(s) of ONE loan installment that is already paid.
     *
     * @return array{ok: bool, cancelled: int, messages: string[]}
     */
    public function stopInstallment(array $debitOrder, int $scheduleId, string $frontEndUserName, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $out = ['ok' => false, 'cancelled' => 0, 'messages' => []];
        $stopAfter = date('Y-m-d', strtotime($today . ' +' . MandateCollectionCheck::IMMINENT_DAYS . ' day'));

        // The debit order's own loan, or one of its top-ups (a combined debit order settles both).
        $row = $this->loans->scheduleRow($scheduleId);
        if ($row) {
            $rowLoan = $this->loans->find((int) $row['loan_id']);
            $own = (int) $row['loan_id'] === (int) $debitOrder['loan_id'];
            if (!$own && (!$rowLoan || (int) ($rowLoan['topup_of_loan_id'] ?? 0) !== (int) $debitOrder['loan_id'])) {
                $row = null;
            }
        }
        if (!$row) {
            $out['messages'][] = 'That installment does not belong to this loan.';
            return $out;
        }
        $notPaid = fn (array $r) => ($r['status'] ?? '') !== 'Paid' && round((float) $r['total_due'] - (float) $r['total_paid'], 2) >= 0.005;
        if ($notPaid($row)) {
            $out['messages'][] = 'Installment #' . (int) $row['installment_no'] . ' is not fully paid, so its collection was NOT stopped.';
            return $out;
        }
        // A combined instalment collects the original loan and its top-ups together: stop it only when every part is paid.
        $partsModel = new \App\Models\DebitOrderSplitLegInstallmentPart();
        foreach ($this->lane->liveRowsForSchedule((int) $debitOrder['id'], $scheduleId) as $liveRow) {
            foreach ($partsModel->forInstallment((int) $liveRow['id']) as $part) {
                $partRow = $this->loans->scheduleRow((int) $part['schedule_id']);
                if ($partRow && $notPaid($partRow)) {
                    $out['messages'][] = 'This installment is a combined debit for a loan and its top-ups, and one of its parts is not fully paid, so the collection was NOT stopped.';
                    return $out;
                }
            }
        }

        $targets = [];   // contract => [ ['no' => int, 'row_id' => ?int] ]
        foreach ($this->lane->liveRowsForSchedule((int) $debitOrder['id'], $scheduleId) as $r) {
            $targets[$r['collexia_api_contract_reference']]['label'] = 'Mandate ' . (int) $r['split_no'];
            $targets[$r['collexia_api_contract_reference']]['leg_id'] = (int) $r['leg_id'];
            $targets[$r['collexia_api_contract_reference']]['rows'][] = ['no' => (int) $r['installment_no'], 'row_id' => (int) $r['id']];
        }
        foreach ($this->lane->oneOffLegsForSchedule((int) $debitOrder['id'], $scheduleId) as $leg) {
            $targets[$leg['collexia_api_contract_reference']] = [
                'label' => 'Mandate ' . (int) $leg['split_no'], 'leg_id' => (int) $leg['id'], 'rows' => [['no' => 1, 'row_id' => null]], 'one_off' => true,
            ];
        }
        if (!$targets) {
            $out['messages'][] = 'No live collection is scheduled for installment #' . (int) $row['installment_no'] . ' -- nothing to stop.';
            $out['ok'] = true;
            return $out;
        }

        foreach ($targets as $contract => $t) {
            if (empty($t['one_off'])) {
                $unconfirmed = array_filter($this->lane->forLeg($t['leg_id']), fn ($r) => $r['applied_at'] === null);
                if ($unconfirmed) {
                    $out['messages'][] = $t['label'] . ' is still being confirmed with Collexia, so nothing was cancelled on it yet -- try again in a few minutes.';
                    continue;
                }
            }
            try {
                $client = ($this->clientFactory)();
                $actual = CollexiaLaneScheduleService::actualInstallments($client->installmentRequest((string) $contract)['installments'] ?? []);
            } catch (\RuntimeException $e) {
                $out['messages'][] = $t['label'] . ': could not read the installments from Collexia (' . $e->getMessage() . ').';
                continue;
            }

            foreach ($t['rows'] as $r) {
                $a = $actual[$r['no']] ?? null;
                if ($a === null) {
                    $out['messages'][] = $t['label'] . ': Collexia does not list installment ' . $r['no'] . '.';
                    continue;
                }
                if ($a['status'] === 2) {
                    if ($r['row_id']) {
                        $this->lane->markCancelled($r['row_id'], 'Paid by hand after the mandate was placed -- already cancelled at Collexia.');
                    }
                    $out['cancelled']++;
                    continue;
                }
                if ($a['status'] !== 1 || $a['date'] <= $stopAfter) {
                    $out['messages'][] = $t['label'] . ': the ' . date('d M Y', strtotime($a['date'])) . ' collection can no longer be stopped (Collexia status ' . $a['status'] . '). If the borrower is debited, refund the overpayment.';
                    continue;
                }
                try {
                    $client->cancelInstallment((string) $contract, $r['no'], $frontEndUserName);
                } catch (\RuntimeException $e) {
                    $out['messages'][] = $t['label'] . ': Collexia did not cancel installment ' . $r['no'] . ' (' . $e->getMessage() . ').';
                    continue;
                }
                if ($r['row_id']) {
                    $this->lane->markCancelled($r['row_id'], 'Paid by hand after the mandate was placed -- collection cancelled at Collexia on request.');
                }
                $out['cancelled']++;
                $out['messages'][] = $t['label'] . ': the ' . date('d M Y', strtotime($a['date'])) . ' collection was cancelled.';
            }
        }

        $out['ok'] = $out['cancelled'] > 0;
        if ($out['cancelled'] > 0) {
            $this->syncAfter($debitOrder);
        }
        return $out;
    }

    /**
     * Single recurring mandate: stop the LAST scheduled collections, as many as the mandate is over-collecting by. The earliest
     * collections are the ones the loan still needs, so the surplus is always taken from the end.
     *
     * @return array{ok: bool, cancelled: int, messages: string[]}
     */
    public function stopSurplus(array $debitOrder, string $frontEndUserName, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $out = ['ok' => false, 'cancelled' => 0, 'messages' => []];
        $contract = (string) ($debitOrder['collexia_api_contract_reference'] ?? '');
        $per = round((float) $debitOrder['debit_amount'], 2);
        if ($contract === '' || $per <= 0) {
            $out['messages'][] = 'This mandate was not placed through the Collexia API, so it cannot be stopped from here -- ask Collexia.';
            return $out;
        }
        $stopAfter = date('Y-m-d', strtotime($today . ' +' . MandateCollectionCheck::IMMINENT_DAYS . ' day'));

        $outstanding = 0.0;
        foreach ($this->loans->schedule((int) $debitOrder['loan_id']) as $r) {
            $outstanding += max(0.0, round((float) $r['total_due'] - (float) $r['total_paid'], 2));
        }
        $outstanding = round($outstanding, 2);

        try {
            $client = ($this->clientFactory)();
            $actual = CollexiaLaneScheduleService::actualInstallments($client->installmentRequest($contract)['installments'] ?? []);
        } catch (\RuntimeException $e) {
            $out['messages'][] = 'Could not read the installments from Collexia (' . $e->getMessage() . ').';
            return $out;
        }

        $active = array_filter($actual, fn ($a) => $a['status'] === 1 && $a['date'] >= $today);
        $surplus = (int) floor((count($active) * $per - $outstanding + 0.01) / $per);
        if ($surplus < 1) {
            $out['messages'][] = 'The mandate is not collecting more than the loan owes -- nothing to stop.';
            $out['ok'] = true;
            return $out;
        }

        $stoppable = array_filter($active, fn ($a) => $a['date'] > $stopAfter);
        uasort($stoppable, fn ($a, $b) => strcmp($b['date'], $a['date']));   // latest first
        $take = array_slice($stoppable, 0, $surplus, true);
        if (count($take) < $surplus) {
            $out['messages'][] = 'Only ' . count($take) . ' of the ' . $surplus . ' surplus collection(s) can still be stopped; the rest are due today or tomorrow.';
        }

        foreach ($take as $no => $a) {
            try {
                $client->cancelInstallment($contract, (int) $no, $frontEndUserName);
            } catch (\RuntimeException $e) {
                $out['messages'][] = 'Collexia did not cancel the ' . date('d M Y', strtotime($a['date'])) . ' collection (' . $e->getMessage() . ').';
                continue;
            }
            $out['cancelled']++;
            $out['messages'][] = 'The ' . date('d M Y', strtotime($a['date'])) . ' collection of ' . 'N$' . number_format($per, 2) . ' was cancelled.';
        }

        $out['ok'] = $out['cancelled'] > 0;
        if ($out['cancelled'] > 0) {
            $this->syncAfter($debitOrder);
        }
        return $out;
    }

    /** Cancelling the last live installment completes the contract at Collexia; re-read it so this screen agrees straight away. */
    private function syncAfter(array $debitOrder): void
    {
        try {
            $fresh = $this->debitOrders->find((int) $debitOrder['id']) ?? $debitOrder;
            (new CollexiaMandateStatusSyncService($this->clientFactory))->syncDebitOrder($fresh);
        } catch (\Throwable $e) {
            // The cancels themselves succeeded; the next scheduled status sync will catch up.
        }
    }
}
