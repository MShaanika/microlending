<?php

namespace App\Services;

use App\Models\CollexiaSetting;
use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;
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
    /**
     * 'Cancelled' is included here (not just 'Load Pending'/'Registered')
     * so a split leg whose parent debit order was JUST cancelled still
     * gets re-enquired -- see DebitOrder::dueForCollexiaStatusSync()'s own
     * docblock for why trusting the cancel API's ack forever was a real
     * gap. This stays bounded to a short window, not a permanent re-poll
     * of every old cancellation, because this loop is only ever reached
     * for debit orders that query already restricts to 'Active'/
     * 'Suspended' or recently-cancelled.
     */
    private const SPLIT_LIVE_STATUSES = ['Load Pending', 'Registered', 'Cancelled'];
    private const MAPPABLE_STATUSES = ['Active', 'Suspended', 'Cancelled', 'Completed'];

    /**
     * Collexia installment statuses that are FINAL (the installment is done: Cancelled, Insufficient
     * Funds, Completed, Rejected, Disputed) versus IN FLIGHT (Active, In Tracking, Validated, Not
     * Validated, Submitted). Collexia does not roll a contract up to Completed itself -- confirmed by
     * Collexia (Donna, 2026-10-21): the contract's final fate is derived on our side from the final
     * fate of ALL its installments. Any status in neither list is treated as still in flight.
     */
    public const FINAL_INSTALLMENT_STATUSES = [2, 3, 6, 8, 10];
    public const IN_FLIGHT_INSTALLMENT_STATUSES = [1, 5, 14, 15, 16];

    private DebitOrder $debitOrders;
    private DebitOrderSplitLeg $splitLegs;
    private DebitOrderSplitLegInstallment $laneInstallments;
    private CollexiaSetting $settings;
    /** @var callable */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? fn () => new CollexiaEndoApiClient();
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
        $this->laneInstallments = new DebitOrderSplitLegInstallment();
        $this->settings = new CollexiaSetting();
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
            $client = ($this->clientFactory)();
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
                $client = ($this->clientFactory)();
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
            // Collexia's own collection date for this leg's one installment is
            // the truth -- it can differ from what we sent (a reschedule made
            // directly in the Collexia portal, or a weekend/holiday moved to
            // the next business day).
            $scheduledDate = self::scheduledDateFromEnquiry($result);
            if ($scheduledDate !== null) {
                $legUpdate['collection_date'] = $scheduledDate;
            }
            if ($mandateStatus !== null) {
                // This leg's own collexia_api_status moves on Collexia's
                // authoritative word, same as the non-split path -- the
                // rollup below (computeRollupStatus()) is only as honest
                // as what each leg actually holds.
                $legUpdate['collexia_api_status'] = self::mapCollexiaApiStatus($mandateStatus);
            }
            $this->splitLegs->updateState($id, (int) $split['split_no'], $legUpdate);
            $this->syncLane($split, $debitOrder, $result, $mandateStatus);
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
     * A lane (one mandate carrying several loan installments): record each
     * installment's real date as Collexia now holds it, and -- once the
     * mandate is Active -- finish moving any installment that hasn't yet been
     * set to its exact planned date/amount (the same step Check Final Fate
     * runs, repeated here so a transient failure there heals on its own).
     */
    private function syncLane(array $leg, array $debitOrder, array $enquiry, ?string $mandateStatus): void
    {
        $rows = $this->laneInstallments->forLeg((int) $leg['id']);
        if (!$rows) {
            return;
        }

        $mandate = $enquiry['mandate'] ?? [];
        if (isset($mandate[0]) && is_array($mandate[0])) {
            $mandate = $mandate[0];
        }
        $actual = CollexiaLaneScheduleService::actualInstallments(is_array($mandate) ? ($mandate['installments'] ?? []) : []);
        foreach ($rows as $row) {
            $no = (int) $row['installment_no'];
            if (isset($actual[$no])) {
                $this->laneInstallments->setCollexiaDate((int) $leg['id'], $no, $actual[$no]['date']);
            }
        }

        $unapplied = array_filter($rows, fn ($r) => $r['applied_at'] === null);
        $user = $this->settings->frontEndUsername();
        if ($unapplied && $mandateStatus === 'Active' && $user !== '') {
            (new CollexiaLaneScheduleService($this->clientFactory))->apply($leg, $debitOrder, $user);
        }
    }

    /** The scheduleDate (YYYY-MM-DD) of a one-installment mandate's installment, or null if the response doesn't carry one. */
    public static function scheduledDateFromEnquiry(array $result): ?string
    {
        $mandate = $result['mandate'] ?? null;
        if (is_array($mandate) && isset($mandate[0]) && is_array($mandate[0])) {
            $mandate = $mandate[0];
        }
        $installments = is_array($mandate) ? ($mandate['installments'] ?? []) : [];
        if (count($installments) !== 1) {
            return null;
        }
        $raw = (string) ($installments[0]['scheduleDate'] ?? '');
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
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
        $label = in_array($label, self::MAPPABLE_STATUSES, true) ? $label : null;

        // Collexia keeps a contract Active after every installment has reached a final result, so
        // an Active contract is promoted to Completed from its installments' final fate. Only ever
        // promotes Active: Cancelled, Suspended and Collexia's own Completed are taken as reported.
        if ($label === 'Active' && self::contractFinalFate($mandate) === 'Completed') {
            return 'Completed';
        }
        return $label;
    }

    /**
     * The contract status implied by its installments (Collexia's rule):
     *   ALL installments in a final status (2,3,6,8,10) -> 'Completed'; anything still in flight -> 'Active'.
     * One installment in a final status (e.g. 3, Insufficient Funds) completes a one-installment contract.
     * Returns null when the response carries no installments, or fewer than the contract's own
     * noOfInstallments (a partial list must never complete a contract that still has installments to come).
     *
     * @param array $mandate one Mandate Enquiry mandate (the element, not the wrapper)
     */
    public static function contractFinalFate(array $mandate): ?string
    {
        $installments = $mandate['installments'] ?? null;
        if (!is_array($installments) || $installments === []) {
            return null;
        }
        $expected = (int) ($mandate['noOfInstallments'] ?? 0);
        if ($expected > 0 && count($installments) < $expected) {
            return null;
        }
        foreach ($installments as $installment) {
            if (!in_array((int) ($installment['status'] ?? 0), self::FINAL_INSTALLMENT_STATUSES, true)) {
                return 'Active';
            }
        }
        return 'Completed';
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
