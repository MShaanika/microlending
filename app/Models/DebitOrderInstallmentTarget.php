<?php

namespace App\Models;

use App\Core\Model;

/**
 * Maps a split debit order's Collexia installment sequence number (1..N,
 * per mandate) onto the specific loan_schedules row it was snapshotted
 * against at placement time -- see database/debit_order_split.sql and
 * DebitOrderCollexiaController::placeSplitMandate(). Only ever populated
 * for split mandates; a non-split debit order has no rows here and keeps
 * relying on Payment::recordAndAllocate()'s FIFO waterfall exactly as
 * before.
 */
class DebitOrderInstallmentTarget extends Model
{
    /**
     * Replaces any prior snapshot for this debit order with a fresh one --
     * safe to call again on a retry after a failed placement, since the
     * loan's unpaid-schedule state may have shifted since the last attempt.
     *
     * @param int[] $scheduleIds ordered, oldest-unpaid-first (DebitOrder::orderedUnpaidScheduleIds())
     */
    public function snapshot(int $debitOrderId, array $scheduleIds): void
    {
        $this->query("DELETE FROM debit_order_installment_targets WHERE debit_order_id = ?", [$debitOrderId]);

        $n = 1;
        foreach ($scheduleIds as $scheduleId) {
            $this->insert('debit_order_installment_targets', [
                'debit_order_id' => $debitOrderId,
                'collexia_installment_no' => $n,
                'schedule_id' => $scheduleId,
            ]);
            $n++;
        }
    }

    public function scheduleIdFor(int $debitOrderId, int $installmentNo): ?int
    {
        $id = $this->scalar(
            "SELECT schedule_id FROM debit_order_installment_targets WHERE debit_order_id = ? AND collexia_installment_no = ?",
            [$debitOrderId, $installmentNo]
        );
        return $id ? (int) $id : null;
    }
}
