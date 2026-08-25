<?php

namespace App\Models;

use App\Core\Model;

/**
 * One row per split transaction (1..10, split_no) of a split debit order
 * mandate -- see database/debit_order_split.sql and
 * database/debit_order_split_nway.sql. Mirrors debit_orders' own
 * collexia_api_* columns, just scoped to a split, so each split's Collexia
 * mandate lifecycle (place/confirm/sync/cancel) can be tracked
 * independently while debit_orders itself keeps a single rolled-up status
 * for the existing UI's button visibility.
 *
 * A row is never deleted. Merging two or more splits cancels them (locally,
 * and at Collexia first if they'd already been sent) and stamps
 * merged_into_id pointing at the new combined row, so the full history
 * stays reconstructable -- see DebitOrderCollexiaController::mergeSplits().
 */
class DebitOrderSplitLeg extends Model
{
    /** Every split for this debit order, including ones merged away -- full history, for the drill-down screen. */
    public function forDebitOrder(int $debitOrderId): array
    {
        return $this->all("SELECT * FROM debit_order_split_legs WHERE debit_order_id = ? ORDER BY split_no", [$debitOrderId]);
    }

    /** Only the currently-live splits (not folded into a later merge) -- what Place/Check/Sync/Cancel Mandate act on. */
    public function activeForDebitOrder(int $debitOrderId): array
    {
        return $this->all("SELECT * FROM debit_order_split_legs WHERE debit_order_id = ? AND merged_into_id IS NULL ORDER BY split_no", [$debitOrderId]);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM debit_order_split_legs WHERE id = ?", [$id]);
    }

    /** Splits actually belonging to this debit order, for the given ids -- used to validate a merge selection came from where it claims to. */
    public function findManyForDebitOrder(int $debitOrderId, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->all(
            "SELECT * FROM debit_order_split_legs WHERE debit_order_id = ? AND id IN ($placeholders)",
            array_merge([$debitOrderId], $ids)
        );
    }

    public function nextSplitNo(int $debitOrderId): int
    {
        $max = $this->scalar("SELECT MAX(split_no) FROM debit_order_split_legs WHERE debit_order_id = ?", [$debitOrderId]);
        return $max ? ((int) $max + 1) : 1;
    }

    /** Creates the split row at registration (or after a merge), or refreshes its amount on a retry after a prior failed attempt. */
    public function upsert(int $debitOrderId, int $splitNo, float $amount, int $totalSplits): int
    {
        $existing = $this->one("SELECT id FROM debit_order_split_legs WHERE debit_order_id = ? AND split_no = ?", [$debitOrderId, $splitNo]);
        if ($existing) {
            $this->update('debit_order_split_legs', ['leg_amount' => $amount, 'total_splits' => $totalSplits], 'id', $existing['id']);
            return (int) $existing['id'];
        }
        return $this->insert('debit_order_split_legs', [
            'debit_order_id' => $debitOrderId,
            'split_no' => $splitNo,
            'total_splits' => $totalSplits,
            'leg_amount' => $amount,
        ]);
    }

    public function updateState(int $debitOrderId, int $splitNo, array $data): bool
    {
        $row = $this->one("SELECT id FROM debit_order_split_legs WHERE debit_order_id = ? AND split_no = ?", [$debitOrderId, $splitNo]);
        if (!$row) {
            return false;
        }
        return $this->update('debit_order_split_legs', $data, 'id', $row['id']);
    }

    public function updateById(int $id, array $data): bool
    {
        return $this->update('debit_order_split_legs', $data, 'id', $id);
    }

    /** Resolves a split's own Collexia contract reference back to its parent debit order -- used by CollexiaPaymentReconciliationService when a collection result's contractReference doesn't match any non-split debit_orders row. */
    public function findByContractNo(string $contractReference): ?array
    {
        return $this->one(
            "SELECT sl.*, d.loan_id, d.borrower_id FROM debit_order_split_legs sl
             JOIN debit_orders d ON d.id = sl.debit_order_id
             WHERE sl.collexia_api_contract_reference = ?",
            [$contractReference]
        );
    }

    /** Whether any payment has ever posted against this split -- once true, it's locked and can never be merged (undoing a real collection needs a refund process, not a merge). */
    public function hasPostedCollection(int $debitOrderId, int $splitNo): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM debit_order_collections WHERE debit_order_id = ? AND split_no = ? AND payment_id IS NOT NULL LIMIT 1",
            [$debitOrderId, $splitNo]
        );
    }
}
