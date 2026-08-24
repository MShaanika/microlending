<?php

namespace App\Models;

use App\Core\Model;

/**
 * One row per leg ('A'/'B') of a split debit order mandate -- see
 * database/debit_order_split.sql. Mirrors debit_orders' own
 * collexia_api_* columns, just scoped to a leg, so each leg's Collexia
 * mandate lifecycle (place/confirm/sync/cancel) can be tracked
 * independently while debit_orders itself keeps a single rolled-up status
 * for the existing UI's button visibility.
 */
class DebitOrderSplitLeg extends Model
{
    public function forDebitOrder(int $debitOrderId): array
    {
        return $this->all("SELECT * FROM debit_order_split_legs WHERE debit_order_id = ? ORDER BY leg", [$debitOrderId]);
    }

    /** Creates the leg row on first placement, or just refreshes leg_amount on a retry after a prior failed attempt. */
    public function upsert(int $debitOrderId, string $leg, float $legAmount): void
    {
        $existing = $this->one("SELECT id FROM debit_order_split_legs WHERE debit_order_id = ? AND leg = ?", [$debitOrderId, $leg]);
        if ($existing) {
            $this->update('debit_order_split_legs', ['leg_amount' => $legAmount], 'id', $existing['id']);
            return;
        }
        $this->insert('debit_order_split_legs', [
            'debit_order_id' => $debitOrderId,
            'leg' => $leg,
            'leg_amount' => $legAmount,
        ]);
    }

    public function updateState(int $debitOrderId, string $leg, array $data): bool
    {
        $row = $this->one("SELECT id FROM debit_order_split_legs WHERE debit_order_id = ? AND leg = ?", [$debitOrderId, $leg]);
        if (!$row) {
            return false;
        }
        return $this->update('debit_order_split_legs', $data, 'id', $row['id']);
    }

    /** Resolves a split leg's own Collexia contract reference back to its parent debit order -- used by CollexiaPaymentReconciliationService when a collection result's contractReference doesn't match any non-split debit_orders row. */
    public function findByContractNo(string $contractReference): ?array
    {
        return $this->one(
            "SELECT sl.*, d.loan_id, d.borrower_id FROM debit_order_split_legs sl
             JOIN debit_orders d ON d.id = sl.debit_order_id
             WHERE sl.collexia_api_contract_reference = ?",
            [$contractReference]
        );
    }
}
