<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLeg;

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

    public function __construct()
    {
        $this->debitOrders = new DebitOrder();
        $this->splitLegs = new DebitOrderSplitLeg();
    }

    /**
     * @return array{debit_order_id: ?int, loan_id: ?int, split_no: ?int}|null null if no mandate/split matches this reference at all.
     */
    public function resolve(string $contractReference): ?array
    {
        if ($contractReference === '') {
            return null;
        }

        $mandate = $this->debitOrders->findByContractNo($contractReference)
            ?? $this->debitOrders->findByCollexiaApiContractReference($contractReference);
        if ($mandate) {
            return ['debit_order_id' => (int) $mandate['id'], 'loan_id' => (int) $mandate['loan_id'], 'split_no' => null];
        }

        $splitRow = $this->splitLegs->findByContractNo($contractReference);
        if ($splitRow) {
            return ['debit_order_id' => (int) $splitRow['debit_order_id'], 'loan_id' => (int) $splitRow['loan_id'], 'split_no' => (int) $splitRow['split_no']];
        }

        return null;
    }
}
