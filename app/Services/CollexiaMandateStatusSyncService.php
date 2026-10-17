<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLeg;
use App\Support\CollexiaV3Codes;

/**
 * One Mandate Enquiry (spec 6.3) per live mandate/split, mapping the
 * response's mandate.status through CollexiaV3Codes::MANDATE_STATUSES onto
 * debit_orders.status -- the shared logic behind both the manual "Sync
 * Status" button (DebitOrderCollexiaController::syncStatus()) and the
 * scheduled bin/sync_collexia_mandate_status.php cron, mirroring how
 * CollexiaPaymentReconciliationService already backs both the manual
 * "Download Payments" button and its own cron.
 *
 * Only 4 of Collexia's 8 mandate statuses have a local ENUM equivalent
 * (Active/Suspended/Cancelled/Completed) -- the other 4 (Data Error/
 * CancelInProgress/SuspendedInProcess/Manually Processed) are left for
 * staff to read from the raw collexia_api_last_response rather than forcing
 * a mismatched local status.
 *
 * Split debit orders: debit_orders.status only ever moves when EVERY
 * currently-live split (SPLIT_LIVE_STATUSES, has a contract reference)
 * reported a clean, comparable mandate status and they all agree -- a mixed
 * result (e.g. one split Completed, another still Active) means the debit
 * order as a whole isn't there yet, so its status is left untouched rather
 * than guessed from a partial picture.
 *
 * collexia_api_status (and, for a split, each leg's own copy) is ALSO kept
 * current here now -- it used to only ever move on placement/cancel, so
 * once Collexia's own mandate reached Completed or Cancelled it stayed
 * frozen at "Registered" forever, which is exactly why a mandate could
 * show status=Completed right next to collexia_api_status=Registered with
 * Cancel/Reschedule still offered. See mapCollexiaApiStatus().
 */
class CollexiaMandateStatusSyncService
{
    private const SPLIT_LIVE_STATUSES = ['Load Pending', 'Registered'];
    private const MAPPABLE_STATUSES = ['Active', 'Suspended', 'Cancelled', 'Completed'];

    private DebitOrder $debitOrders;
    private DebitOrderSplitLeg $splitLegs;

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
    }

    /**
     * @return array{synced: bool, error: ?string, mandate_status: ?string}
     */
    public function syncDebitOrder(array $debitOrder): array
    {
        if ((int) ($debitOrder['split_enabled'] ?? 0) === 1) {
            return $this->syncSplitDebitOrder($debitOrder);
        }

        if (!$debitOrder['collexia_api_contract_reference']) {
            return ['synced' => false, 'error' => 'This mandate has not been placed yet.', 'mandate_status' => null];
        }

        try {
            $client = new CollexiaEndoApiClient();
            $result = $client->mandateEnquiry(['contractReference' => $debitOrder['collexia_api_contract_reference']]);
        } catch (\RuntimeException $e) {
            return ['synced' => false, 'error' => $e->getMessage(), 'mandate_status' => null];
        }

        $update = [
            'collexia_api_last_response' => json_encode($result),
            'collexia_api_synced_at' => date('Y-m-d H:i:s'),
        ];
        $mandateStatus = $this->mapMandateStatus($result);
        if ($mandateStatus !== null) {
            $update['status'] = $mandateStatus;
            $update['collexia_api_status'] = self::mapCollexiaApiStatus($mandateStatus);
        }

        $this->debitOrders->updateCollexiaApiState((int) $debitOrder['id'], $update);

        return ['synced' => true, 'error' => null, 'mandate_status' => $mandateStatus];
    }

    /**
     * @return array{synced: bool, error: ?string, mandate_status: ?string}
     */
    private function syncSplitDebitOrder(array $debitOrder): array
    {
        $id = (int) $debitOrder['id'];
        $splits = $this->splitLegs->activeForDebitOrder($id);
        $attempted = false;
        $mandateStatuses = [];
        $eligibleCount = 0;

        foreach ($splits as $split) {
            if (!in_array($split['collexia_api_status'], self::SPLIT_LIVE_STATUSES, true) || !$split['collexia_api_contract_reference']) {
                continue;
            }
            $eligibleCount++;
            $attempted = true;

            try {
                $client = new CollexiaEndoApiClient();
                $result = $client->mandateEnquiry(['contractReference' => $split['collexia_api_contract_reference']]);
            } catch (\RuntimeException $e) {
                return ['synced' => false, 'error' => $e->getMessage(), 'mandate_status' => null];
            }

            $mandateStatus = $this->mapMandateStatus($result);
            if ($mandateStatus !== null) {
                $mandateStatuses[] = $mandateStatus;
            }

            $legUpdate = [
                'collexia_api_last_response' => json_encode($result),
                'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            ];
            if ($mandateStatus !== null) {
                // This leg's own collexia_api_status moves on Collexia's
                // authoritative word, same as the non-split path -- the
                // rollup below (computeRollupStatus()) is only as honest
                // as what each leg actually holds.
                $legUpdate['collexia_api_status'] = self::mapCollexiaApiStatus($mandateStatus);
            }
            $this->splitLegs->updateState($id, (int) $split['split_no'], $legUpdate);
        }

        if (!$attempted) {
            return ['synced' => false, 'error' => 'No split transaction has been placed yet.', 'mandate_status' => null];
        }

        $update = [
            'collexia_api_synced_at' => date('Y-m-d H:i:s'),
            // Recomputed fresh from what every leg now actually holds
            // (just updated above) -- this is what makes 'Mixed' show up
            // here the moment two legs' real Collexia statuses diverge,
            // rather than only at placement/retry time.
            'collexia_api_status' => $this->splitLegs->computeRollupStatus($id),
        ];
        $rolledUpStatus = null;
        if (count($mandateStatuses) === $eligibleCount && count(array_unique($mandateStatuses)) === 1) {
            $rolledUpStatus = $mandateStatuses[0];
            $update['status'] = $rolledUpStatus;
        }

        $this->debitOrders->updateCollexiaApiState($id, $update);

        return ['synced' => true, 'error' => null, 'mandate_status' => $rolledUpStatus];
    }

    /**
     * Extracts mandate.status from a Mandate Enquiry response and maps it
     * through CollexiaV3Codes::MANDATE_STATUSES, returning it ONLY when
     * it's one of the 4 mappable values. Returns null for anything not
     * shaped like a Mandate Enquiry response (e.g. a stored
     * load-confirmation response), so this never misfires against the
     * wrong response type.
     */
    public function mapMandateStatus(array $result): ?string
    {
        $mandate = $result['mandate'] ?? null;
        if (is_array($mandate) && isset($mandate[0]) && is_array($mandate[0])) {
            $mandate = $mandate[0];
        }
        if (!is_array($mandate) || !isset($mandate['status'])) {
            return null;
        }

        $label = CollexiaV3Codes::MANDATE_STATUSES[(int) $mandate['status']] ?? null;
        return in_array($label, self::MAPPABLE_STATUSES, true) ? $label : null;
    }

    /**
     * Maps a mapMandateStatus() result (debit_orders.status's own vocabulary:
     * Active/Suspended/Cancelled/Completed) onto collexia_api_status's
     * vocabulary (Not Placed/Load Pending/Registered/Load Failed/Cancelled/
     * Uncertain/Completed/Mixed) -- Active and Suspended both mean the
     * mandate still genuinely exists at Collexia (Suspended is a pause, not
     * a stop), so both map to 'Registered'; status's own ENUM is what
     * actually distinguishes Active from Suspended for anyone reading it.
     */
    private static function mapCollexiaApiStatus(string $mandateStatus): string
    {
        return match ($mandateStatus) {
            'Cancelled' => 'Cancelled',
            'Completed' => 'Completed',
            default => 'Registered', // Active, Suspended
        };
    }
}
