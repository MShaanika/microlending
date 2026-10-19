<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;

/**
 * Finishes placing split mandates without anyone having to click "Check Final
 * Fate": for every leg still "Load Pending" it asks Collexia whether the
 * mandate registered (Request Final Fate), marks it Registered, and -- for a
 * lane that carries several loan installments -- immediately moves each
 * installment to its exact date and amount (CollexiaLaneScheduleService).
 *
 * Collexia confirms a freshly loaded mandate asynchronously, so a leg that is
 * not loaded YET is left Load Pending and checked again on the next run; it is
 * only declared Load Failed once it has been pending for longer than
 * $maxPendingMinutes. That avoids the failure mode of marking a mandate
 * "failed" a second after submitting it (which would invite a duplicate
 * placement of one that is in fact about to register).
 *
 * Used straight after placement (the Register & Send button waits for the
 * result) and by bin/finalize_pending_collexia_mandates.php for anything that
 * had not confirmed yet.
 */
class CollexiaMandateFinalizer
{
    private DebitOrder $debitOrders;
    private DebitOrderSplitLeg $splitLegs;
    private DebitOrderSplitLegInstallment $laneInstallments;
    /** @var callable */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
        $this->laneInstallments = new DebitOrderSplitLegInstallment();
        $this->clientFactory = $clientFactory ?? fn () => new CollexiaEndoApiClient();
    }

    /**
     * @return array{checked: int, registered: int, failed: int, pending: int, warnings: string[]}
     */
    public function finalizeDebitOrder(array $debitOrder, string $frontEndUserName, int $maxPendingMinutes = 15): array
    {
        $result = ['checked' => 0, 'registered' => 0, 'failed' => 0, 'pending' => 0, 'warnings' => []];
        $id = (int) $debitOrder['id'];

        foreach ($this->splitLegs->activeForDebitOrder($id) as $leg) {
            if ($leg['collexia_api_status'] !== 'Load Pending' || empty($leg['collexia_api_contract_reference'])) {
                continue;
            }
            $result['checked']++;

            try {
                $client = ($this->clientFactory)();
                $response = $client->requestFinalFate((string) $leg['collexia_api_contract_reference'], $frontEndUserName);
            } catch (\RuntimeException $e) {
                // Couldn't even ask -- leave it Load Pending and try again later.
                $result['pending']++;
                $result['warnings'][] = 'Mandate ' . (int) $leg['split_no'] . ': ' . $e->getMessage();
                continue;
            }

            if (!empty($response['mandateLoaded'])) {
                $this->splitLegs->updateState($id, (int) $leg['split_no'], [
                    'collexia_api_status' => 'Registered',
                    'collexia_api_last_response' => json_encode($response),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $result['registered']++;

                if ($this->laneInstallments->isLane((int) $leg['id'])) {
                    $applied = (new CollexiaLaneScheduleService($this->clientFactory))->apply($leg, $debitOrder, $frontEndUserName);
                    if (!$applied['success']) {
                        $result['warnings'][] = 'Mandate ' . (int) $leg['split_no'] . ' dates/amounts: ' . $applied['message'];
                    }
                }
                continue;
            }

            $placedAt = strtotime((string) ($leg['collexia_api_synced_at'] ?? '')) ?: time();
            if (time() - $placedAt > $maxPendingMinutes * 60) {
                $this->splitLegs->updateState($id, (int) $leg['split_no'], [
                    'collexia_api_status' => 'Load Failed',
                    'collexia_api_last_response' => json_encode($response),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                $result['failed']++;
            } else {
                $result['pending']++;
            }
        }

        if ($result['checked'] > 0) {
            $this->debitOrders->updateCollexiaApiState($id, [
                'collexia_api_status' => $this->splitLegs->computeRollupStatus($id),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $result;
    }

    /**
     * Every debit order that still has a Load Pending leg -- what the cron job
     * walks through.
     *
     * @return array{debit_orders: int, registered: int, failed: int, pending: int, warnings: string[]}
     */
    public function finalizeAllPending(string $frontEndUserName, int $maxPendingMinutes = 15): array
    {
        $total = ['debit_orders' => 0, 'registered' => 0, 'failed' => 0, 'pending' => 0, 'warnings' => []];
        foreach ($this->splitLegs->debitOrderIdsWithPendingLoad() as $debitOrderId) {
            $debitOrder = $this->debitOrders->find((int) $debitOrderId);
            if (!$debitOrder) {
                continue;
            }
            $one = $this->finalizeDebitOrder($debitOrder, $frontEndUserName, $maxPendingMinutes);
            $total['debit_orders']++;
            foreach (['registered', 'failed', 'pending'] as $key) {
                $total[$key] += $one[$key];
            }
            $total['warnings'] = array_merge($total['warnings'], $one['warnings']);
        }
        return $total;
    }
}
