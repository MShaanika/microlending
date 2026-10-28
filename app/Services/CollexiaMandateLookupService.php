<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Models\DebitOrderInstallmentTarget;
use App\Models\DebitOrderSplitLeg;
use App\Models\DebitOrderSplitLegInstallment;
use App\Models\DebitOrderSplitLegInstallmentPart;

/**
 * Resolves a contract reference from ANY Collexia report/response
 * (Download Payments, an Excel report export, Mandate Enquiry) back to the
 * local debit order it belongs to -- a single contract reference can only
 * ever match one of three independently-populated places, so every caller
 * that matches Collexia data back to a mandate needs to try all three, not
 * just the first one that happens to apply to most rows:
 *
 * 1. debit_orders.merchant_system_contract_no -- the legacy "EnDo Batch
 *    v1.0" Excel-exchange contract number.
 * 2. debit_orders.collexia_api_contract_reference -- a non-split mandate
 *    placed through the newer EnDO REST API (DebitOrderCollexiaController::
 *    placeSingleMandate()). This is a SEPARATE column/identifier from (1),
 *    generated independently -- a debit order placed via the API still has
 *    its own auto-generated merchant_system_contract_no sitting unused
 *    alongside it, so checking only (1) silently misses every API-placed,
 *    non-split mandate's payments.
 * 3. debit_order_split_legs.collexia_api_contract_reference -- each split
 *    leg of a split debit order, which only ever has an API-placed
 *    reference (split mandates don't exist in the legacy batch flow).
 *
 * Found 2026-09-15 while cross-referencing real Collexia report exports:
 * CollexiaPaymentReconciliationService (Download Payments) and
 * DebitOrderCollectionController::store() (Excel report imports) had both
 * only ever checked (1), meaning no payment for a non-split, API-placed
 * mandate could ever be matched/posted by either path. Split mandates were
 * unaffected -- DebitOrderSplitLeg::findByContractNo() already queried the
 * right column.
 */
class CollexiaMandateLookupService
{
    private DebitOrder $debitOrders;
    private DebitOrderSplitLeg $splitLegs;
    private DebitOrderSplitLegInstallment $laneInstallments;
    private DebitOrderInstallmentTarget $installmentTargets;
    private DebitOrderSplitLegInstallmentPart $laneParts;

    public function __construct()
    {
        $this->laneParts = new DebitOrderSplitLegInstallmentPart();
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
        $this->laneInstallments = new DebitOrderSplitLegInstallment();
        $this->installmentTargets = new DebitOrderInstallmentTarget();
    }

    /**
     * Which loan_schedules row a collected installment settles, for a matched split mandate: the leg's own
     * single schedule_id (a one-off leg), else -- for a lane carrying several installments -- the lane's
     * installment-number mapping, else the debit order's original installment snapshot (legs placed before
     * either existed). Null when none of them know, so the collection is recorded unposted for review.
     */
    public function scheduleIdFor(array $mandate, int $installmentNo): ?int
    {
        if (!empty($mandate['schedule_id'])) {
            return (int) $mandate['schedule_id'];
        }
        if (!empty($mandate['leg_id'])) {
            $id = $this->laneInstallments->scheduleIdFor((int) $mandate['leg_id'], $installmentNo);
            if ($id) {
                return $id;
            }
        }
        return $this->installmentTargets->scheduleIdFor((int) $mandate['debit_order_id'], $installmentNo);
    }

    /**
     * The schedule rows a COMBINED instalment settles (the original loan's and its top-ups'), or [] for an ordinary instalment -- one row,
     * one loan, posted exactly as before. Needs the matched lane (leg_id) and Collexia's installment number.
     */
    public function partsFor(array $mandate, int $installmentNo): array
    {
        if (empty($mandate['leg_id'])) {
            return [];
        }
        return $this->laneParts->forLaneInstallment((int) $mandate['leg_id'], $installmentNo);
    }

    /**
     * @return array{debit_order_id: ?int, loan_id: ?int, split_no: ?int, schedule_id: ?int, leg_id: ?int}|null null if no mandate/split matches this reference at all.
     */
    public function resolve(string $contractReference): ?array
    {
        if ($contractReference === '') {
            return null;
        }

        $mandate = $this->debitOrders->findByContractNo($contractReference)
            ?? $this->debitOrders->findByCollexiaApiContractReference($contractReference);
        if ($mandate) {
            return ['debit_order_id' => (int) $mandate['id'], 'loan_id' => (int) $mandate['loan_id'], 'split_no' => null, 'schedule_id' => null, 'leg_id' => null];
        }

        $splitRow = $this->splitLegs->findByContractNo($contractReference);
        if ($splitRow) {
            return [
                'debit_order_id' => (int) $splitRow['debit_order_id'],
                'loan_id' => (int) $splitRow['loan_id'],
                'split_no' => (int) $splitRow['split_no'],
                'leg_id' => (int) $splitRow['id'],
                // The leg's own unambiguous target -- see
                // DebitOrderSplitLeg's own docblock for why this replaces
                // the old installmentNo-keyed snapshot lookup. Null for a
                // leg placed before this was tracked (falls back to the
                // old lookup at the call site).
                'schedule_id' => $splitRow['schedule_id'] !== null ? (int) $splitRow['schedule_id'] : null,
            ];
        }

        return null;
    }
}
