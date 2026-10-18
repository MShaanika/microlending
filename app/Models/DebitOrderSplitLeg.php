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
        return $this->all(
            "SELECT sl.*, ls.installment_no, ls.due_date AS installment_due_date
             FROM debit_order_split_legs sl
             LEFT JOIN loan_schedules ls ON ls.id = sl.schedule_id
             WHERE sl.debit_order_id = ? ORDER BY sl.split_no",
            [$debitOrderId]
        );
    }

    /** Only the currently-live splits (not folded into a later merge) -- what Place/Check/Sync/Cancel Mandate act on. */
    public function activeForDebitOrder(int $debitOrderId): array
    {
        return $this->all("SELECT * FROM debit_order_split_legs WHERE debit_order_id = ? AND merged_into_id IS NULL ORDER BY split_no", [$debitOrderId]);
    }

    /**
     * Rolls every live split's own collexia_api_status up into a single
     * summary value for debit_orders.collexia_api_status -- worst-first:
     * any Load Failed wins (needs retry), else any Load Pending (in
     * flight), else the legs' shared status when every one of them
     * genuinely agrees (including Registered, Cancelled, Completed, or
     * Uncertain when ALL legs independently reached that), else 'Mixed'
     * when they've diverged in any other way (e.g. one Registered, one
     * Cancelled). 'Mixed' deliberately replaces an earlier version of this
     * logic that silently fell back to 'Registered' for any non-unanimous
     * case not covered by the first two branches -- that hid a leg that
     * had actually stopped collecting behind a falsely-reassuring status.
     * Splits already folded away by a merge are excluded -- they're
     * permanently Cancelled and irrelevant to the live rollup.
     */
    public function computeRollupStatus(int $debitOrderId): string
    {
        return self::rollupFromStatuses(array_column($this->activeForDebitOrder($debitOrderId), 'collexia_api_status'));
    }

    /** Pure rule behind computeRollupStatus() -- see that method's docblock. Static/public so it's testable without a DB. */
    public static function rollupFromStatuses(array $statuses): string
    {
        if (empty($statuses)) {
            return 'Not Placed';
        }
        if (in_array('Load Failed', $statuses, true)) {
            return 'Load Failed';
        }
        if (in_array('Load Pending', $statuses, true)) {
            return 'Load Pending';
        }
        if (count(array_unique($statuses)) === 1) {
            return $statuses[0];
        }
        return 'Mixed';
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

    /** The original split(s) that were folded into $mergedIntoId by a prior merge -- used to un-merge a failed combined split back into its constituent amounts. */
    public function mergeSourcesFor(int $mergedIntoId): array
    {
        return $this->all("SELECT * FROM debit_order_split_legs WHERE merged_into_id = ? ORDER BY split_no", [$mergedIntoId]);
    }

    public function nextSplitNo(int $debitOrderId): int
    {
        $max = $this->scalar("SELECT MAX(split_no) FROM debit_order_split_legs WHERE debit_order_id = ?", [$debitOrderId]);
        return $max ? ((int) $max + 1) : 1;
    }

    /**
     * Creates the split row at registration (or after a merge), or
     * refreshes its amount on a retry after a prior failed attempt.
     * scheduleId is the specific loan_schedules row this leg's share
     * belongs to -- see this class's own docblock and
     * database/debit_order_split_leg_schedule_attribution.sql for why this
     * (not Collexia's own always-1 per-leg installmentNo) is what makes
     * multi-month splits attributable to the right month at all.
     */
    public function upsert(int $debitOrderId, int $splitNo, float $amount, int $totalSplits, ?int $scheduleId = null, ?string $collectionDate = null): int
    {
        $existing = $this->one("SELECT id FROM debit_order_split_legs WHERE debit_order_id = ? AND split_no = ?", [$debitOrderId, $splitNo]);
        if ($existing) {
            $data = ['leg_amount' => $amount, 'total_splits' => $totalSplits];
            if ($scheduleId !== null) {
                $data['schedule_id'] = $scheduleId;
            }
            if ($collectionDate !== null) {
                $data['collection_date'] = $collectionDate;
            }
            $this->update('debit_order_split_legs', $data, 'id', $existing['id']);
            return (int) $existing['id'];
        }
        return $this->insert('debit_order_split_legs', [
            'debit_order_id' => $debitOrderId,
            'split_no' => $splitNo,
            'total_splits' => $totalSplits,
            'leg_amount' => $amount,
            'schedule_id' => $scheduleId,
            'collection_date' => $collectionDate,
        ]);
    }

    /**
     * Live (not merged-away) splits sharing one target installment --
     * total_splits is scoped per schedule_id, not per debit order, so a
     * later month's "1 of 2" / "2 of 2" display never reads as part of an
     * earlier month's split set just because they share a debit_order_id.
     */
    public function liveForSchedule(int $debitOrderId, int $scheduleId): array
    {
        return $this->all(
            "SELECT * FROM debit_order_split_legs WHERE debit_order_id = ? AND schedule_id = ? AND merged_into_id IS NULL ORDER BY split_no",
            [$debitOrderId, $scheduleId]
        );
    }

    /**
     * The loan's next unpaid installment that isn't already the target of
     * a live split leg on this debit order -- what "Place Next
     * Installment's Splits" registers against. Null once every unpaid
     * installment is already covered (or the loan has none left).
     */
    public function nextUncoveredScheduleId(int $debitOrderId, int $loanId): ?int
    {
        $id = $this->scalar(
            "SELECT ls.id FROM loan_schedules ls
             WHERE ls.loan_id = ? AND ls.status != 'Paid'
               AND ls.id NOT IN (
                 SELECT schedule_id FROM debit_order_split_legs
                 WHERE debit_order_id = ? AND merged_into_id IS NULL AND schedule_id IS NOT NULL
               )
             ORDER BY ls.installment_no LIMIT 1",
            [$loanId, $debitOrderId]
        );
        return $id ? (int) $id : null;
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
