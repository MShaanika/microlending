<?php

namespace App\Services;

use App\Core\Auth;
use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLeg;

/**
 * The one real "cancel this debit order's live mandate(s) at Collexia"
 * code path -- shared by the direct "Cancel Mandate" button
 * (DebitOrderCollexiaController::cancelMandate()) and
 * DebitOrderCancellationController::approve(), which used to only flip
 * debit_orders.status to 'Cancelled' locally without ever telling
 * Collexia -- a real safety gap: the record said "Cancelled" while
 * Collexia could still be actively collecting against it. Both callers
 * now go through here, so approving a cancellation request genuinely
 * cancels the mandate first; if that fails, the request stays Pending
 * rather than being silently marked done.
 */
class CollexiaMandateCancellationService
{
    /** A split leg in any of these states still has something live to cancel at Collexia. */
    private const SPLIT_LIVE_STATUSES = ['Registered', 'Load Pending', 'Uncertain'];

    private CollexiaSetting $settings;
    private DebitOrder $debitOrders;
    private DebitOrderSplitLeg $splitLegs;

    public function __construct()
    {
        $this->settings = new CollexiaSetting();
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
    }

    /**
     * @return array{success: bool, message: string, attempted: bool}
     * attempted=false means there was nothing live to cancel at Collexia
     * (never placed, or already Cancelled/Completed) -- not a failure, the
     * caller can proceed with whatever local-only bookkeeping it needs.
     */
    public function cancel(array $debitOrder): array
    {
        return (int) ($debitOrder['split_enabled'] ?? 0) === 1
            ? $this->cancelSplit($debitOrder)
            : $this->cancelSingle($debitOrder);
    }

    private function cancelSingle(array $debitOrder): array
    {
        $id = (int) $debitOrder['id'];
        if (!in_array($debitOrder['collexia_api_status'] ?? '', self::SPLIT_LIVE_STATUSES, true) || !$debitOrder['collexia_api_contract_reference']) {
            return ['success' => true, 'message' => '', 'attempted' => false];
        }

        try {
            $client = new CollexiaEndoApiClient();
            $result = $client->cancelMandate((string) $debitOrder['collexia_api_contract_reference'], $this->frontEndUserName());

            $this->debitOrders->updateCollexiaApiState($id, [
                'collexia_api_status' => 'Cancelled',
                'collexia_api_last_response' => json_encode($result),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ]);

            return ['success' => true, 'message' => 'Mandate cancelled at Collexia.', 'attempted' => true];
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'attempted' => true];
        }
    }

    private function cancelSplit(array $debitOrder): array
    {
        $id = (int) $debitOrder['id'];
        $splits = $this->splitLegs->activeForDebitOrder($id);
        $attempted = false;

        foreach ($splits as $split) {
            if (!in_array($split['collexia_api_status'], self::SPLIT_LIVE_STATUSES, true) || !$split['collexia_api_contract_reference']) {
                continue;
            }
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->cancelMandate((string) $split['collexia_api_contract_reference'], $this->frontEndUserName());

                $this->splitLegs->updateState($id, (int) $split['split_no'], [
                    'collexia_api_status' => 'Cancelled',
                    'collexia_api_last_response' => json_encode($result),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\RuntimeException $e) {
                // Whatever already got cancelled above in this loop stays
                // cancelled -- only the remaining legs failed. Rolling up
                // now correctly shows 'Mixed' rather than silently
                // implying nothing happened.
                $this->debitOrders->updateCollexiaApiState($id, [
                    'collexia_api_status' => $this->splitLegs->computeRollupStatus($id),
                    'collexia_api_synced_at' => date('Y-m-d H:i:s'),
                ]);
                return ['success' => false, 'message' => $e->getMessage(), 'attempted' => true];
            }
        }

        if (!$attempted) {
            return ['success' => true, 'message' => '', 'attempted' => false];
        }

        $this->debitOrders->updateCollexiaApiState($id, [
            'collexia_api_status' => $this->splitLegs->computeRollupStatus($id),
            'collexia_api_synced_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => 'Every live split transaction cancelled at Collexia.', 'attempted' => true];
    }

    private function frontEndUserName(): string
    {
        $configured = $this->settings->frontEndUsername();
        return $configured !== '' ? $configured : (string) (Auth::user()['username'] ?? '');
    }
}
