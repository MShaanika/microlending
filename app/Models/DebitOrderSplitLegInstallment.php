<?php

namespace App\Models;

use App\Core\Model;

/**
 * The installments carried by one split "lane" -- a single Collexia mandate
 * loaded with several loan installments. See
 * database/debit_order_split_leg_installments.sql for the full picture.
 */
class DebitOrderSplitLegInstallment extends Model
{
    /**
     * $autoCancel marks an installment that was already paid by hand and is to be cancelled at Collexia once the mandate is confirmed.
     * The column is only written when set, so registering an ordinary mandate never touches it.
     */
    public function add(int $legId, int $installmentNo, int $scheduleId, string $plannedDate, float $plannedAmount, bool $autoCancel = false): int
    {
        return $this->insert('debit_order_split_leg_installments', [
            'leg_id' => $legId,
            'installment_no' => $installmentNo,
            'schedule_id' => $scheduleId,
            'planned_date' => $plannedDate,
            'planned_amount' => $plannedAmount,
        ] + ($autoCancel ? ['auto_cancel' => 1] : []));
    }

    /**
     * The live lane installments (not yet cancelled) that settle one loan installment, across this debit order's registered mandates.
     * Each carries its mandate's contract reference and split number.
     */
    public function liveRowsForSchedule(int $debitOrderId, int $scheduleId): array
    {
        return $this->all(
            "SELECT li.*, sl.id AS leg_id, sl.split_no, sl.collexia_api_contract_reference
             FROM debit_order_split_leg_installments li
             JOIN debit_order_split_legs sl ON sl.id = li.leg_id
             WHERE sl.debit_order_id = ? AND sl.merged_into_id IS NULL AND sl.collexia_api_status = 'Registered'
               AND sl.collexia_api_contract_reference IS NOT NULL AND li.schedule_id = ? AND li.cancelled_at IS NULL
             ORDER BY sl.split_no, li.installment_no",
            [$debitOrderId, $scheduleId]
        );
    }

    /** Older-style split mandates: one mandate per installment, aimed at the loan installment directly (no lane rows). */
    public function oneOffLegsForSchedule(int $debitOrderId, int $scheduleId): array
    {
        return $this->all(
            "SELECT sl.* FROM debit_order_split_legs sl
             WHERE sl.debit_order_id = ? AND sl.schedule_id = ? AND sl.merged_into_id IS NULL AND sl.collexia_api_status = 'Registered'
               AND sl.collexia_api_contract_reference IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM debit_order_split_leg_installments li WHERE li.leg_id = sl.id)
             ORDER BY sl.split_no",
            [$debitOrderId, $scheduleId]
        );
    }

    /** A lane's paid-by-hand installments that Collexia has not been told to cancel yet. */
    public function pendingAutoCancel(int $legId): array
    {
        return $this->all(
            "SELECT * FROM debit_order_split_leg_installments WHERE leg_id = ? AND auto_cancel = 1 AND cancelled_at IS NULL ORDER BY installment_no",
            [$legId]
        );
    }

    public function markCancelled(int $id, string $note): void
    {
        $this->query(
            "UPDATE debit_order_split_leg_installments SET cancelled_at = NOW(), cancel_note = ? WHERE id = ?",
            [substr($note, 0, 255), $id]
        );
    }

    /** The installment turned out to need collecting after all (its payment was reversed): stop treating it as paid by hand. */
    public function clearAutoCancel(int $id, string $note): void
    {
        $this->query("UPDATE debit_order_split_leg_installments SET auto_cancel = 0, cancel_note = ? WHERE id = ? AND cancelled_at IS NULL", [substr($note, 0, 255), $id]);
    }

    public function noteCancelAttempt(int $id, string $note): void
    {
        $this->query("UPDATE debit_order_split_leg_installments SET cancel_note = ? WHERE id = ? AND cancelled_at IS NULL", [substr($note, 0, 255), $id]);
    }

    /** How many installments of this lane are NOT paid-by-hand ones, i.e. will actually be collected. */
    public function liveCount(int $legId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM debit_order_split_leg_installments WHERE leg_id = ? AND auto_cancel = 0", [$legId]);
    }

    /**
     * Registered mandates that still have a paid-by-hand installment waiting to be cancelled -- what the finalize cron retries after a
     * cancel that could not be sent (network, Collexia busy). Only mandates whose dates/amounts are fully confirmed qualify.
     *
     * @return int[] debit order ids
     */
    public function debitOrderIdsWithPendingAutoCancel(): array
    {
        return array_map('intval', array_column($this->all(
            "SELECT DISTINCT sl.debit_order_id
             FROM debit_order_split_leg_installments li
             JOIN debit_order_split_legs sl ON sl.id = li.leg_id
             WHERE li.auto_cancel = 1 AND li.cancelled_at IS NULL
               AND sl.collexia_api_status = 'Registered' AND sl.merged_into_id IS NULL
             ORDER BY sl.debit_order_id"
        ), 'debit_order_id'));
    }

    /** Every installment of one lane, in Collexia's own sequence order. */
    public function forLeg(int $legId): array
    {
        return $this->all(
            "SELECT li.*, ls.installment_no AS loan_installment_no, ls.due_date AS loan_due_date
             FROM debit_order_split_leg_installments li
             JOIN loan_schedules ls ON ls.id = li.schedule_id
             WHERE li.leg_id = ? ORDER BY li.installment_no",
            [$legId]
        );
    }

    /** @return array<int, array> lane installments grouped by leg id */
    public function forLegs(array $legIds): array
    {
        if (!$legIds) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($legIds), '?'));
        $grouped = [];
        foreach ($this->all(
            "SELECT li.*, ls.installment_no AS loan_installment_no, ls.due_date AS loan_due_date
             FROM debit_order_split_leg_installments li
             JOIN loan_schedules ls ON ls.id = li.schedule_id
             WHERE li.leg_id IN ($marks) ORDER BY li.leg_id, li.installment_no",
            array_values($legIds)
        ) as $row) {
            $grouped[(int) $row['leg_id']][] = $row;
        }
        return $grouped;
    }

    public function scheduleIdFor(int $legId, int $installmentNo): ?int
    {
        $id = $this->scalar(
            "SELECT schedule_id FROM debit_order_split_leg_installments WHERE leg_id = ? AND installment_no = ?",
            [$legId, $installmentNo]
        );
        return $id ? (int) $id : null;
    }

    /**
     * Applies a merged plan from SplitLanePlanner::mergeParts() to one lane: drops the merged-away parts, updates amounts and closes up the
     * installment numbers. All in one transaction. installment_no is unique per lane, so the numbers are first moved out of the way and then
     * set, never overwritten in place.
     *
     * @param array<int, array{id: int, installment_no: int, planned_amount: float}> $rows the lane's remaining rows with their new numbers
     * @param int[] $deletedIds
     */
    public function applyMerge(int $legId, array $rows, array $deletedIds): void
    {
        $this->transaction(function () use ($legId, $rows, $deletedIds) {
            foreach ($deletedIds as $id) {
                $this->query('DELETE FROM debit_order_split_leg_installments WHERE id = ? AND leg_id = ?', [(int) $id, $legId]);
            }
            $this->query('UPDATE debit_order_split_leg_installments SET installment_no = installment_no + 1000 WHERE leg_id = ?', [$legId]);
            foreach ($rows as $row) {
                $this->query(
                    'UPDATE debit_order_split_leg_installments SET installment_no = ?, planned_amount = ? WHERE id = ? AND leg_id = ?',
                    [(int) $row['installment_no'], round((float) $row['planned_amount'], 2), (int) $row['id'], $legId]
                );
            }
        });
    }

    public function isLane(int $legId): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM debit_order_split_leg_installments WHERE leg_id = ? LIMIT 1", [$legId]);
    }

    public function markApplied(int $id): void
    {
        $this->update('debit_order_split_leg_installments', ['applied_at' => date('Y-m-d H:i:s')], 'id', $id);
    }

    public function setCollexiaDate(int $legId, int $installmentNo, string $date): void
    {
        $this->query(
            "UPDATE debit_order_split_leg_installments SET collexia_date = ? WHERE leg_id = ? AND installment_no = ?",
            [$date, $legId, $installmentNo]
        );
    }

    public function setPlanned(int $legId, int $installmentNo, string $date, float $amount): void
    {
        $this->query(
            "UPDATE debit_order_split_leg_installments SET planned_date = ?, planned_amount = ?, collexia_date = ?, applied_at = NOW() WHERE leg_id = ? AND installment_no = ?",
            [$date, $amount, $date, $legId, $installmentNo]
        );
    }

    /**
     * Which Collexia mandate(s) carry each loan installment of this debit
     * order -- one entry for an unsplit installment, several when it was
     * split (each part rides in its own mandate). Covers both a lane (an
     * installment of a mandate carrying several) and a one-off leg (a
     * mandate for a single installment).
     *
     * @return array<int, array<int, array>> carriers grouped by loan_schedules id, mandates in split_no order
     */
    public function carriersBySchedule(int $debitOrderId): array
    {
        $rows = $this->all(
            "SELECT li.schedule_id, sl.id AS leg_id, sl.split_no, sl.total_splits, sl.collexia_api_contract_reference AS contract_reference,
                    sl.collexia_api_status AS status, li.installment_no AS mandate_installment_no,
                    li.planned_date, li.collexia_date, li.planned_amount AS amount, li.applied_at, li.auto_cancel, li.cancelled_at,
                    li.id AS lane_installment_id
             FROM debit_order_split_leg_installments li
             JOIN debit_order_split_legs sl ON sl.id = li.leg_id
             WHERE sl.debit_order_id = ? AND sl.merged_into_id IS NULL
             UNION ALL
             SELECT sl.schedule_id, sl.id, sl.split_no, sl.total_splits, sl.collexia_api_contract_reference,
                    sl.collexia_api_status, 1, sl.collection_date, NULL, sl.leg_amount, NULL, 0, NULL, NULL
             FROM debit_order_split_legs sl
             WHERE sl.debit_order_id = ? AND sl.merged_into_id IS NULL AND sl.schedule_id IS NOT NULL
             ORDER BY schedule_id, split_no",
            [$debitOrderId, $debitOrderId]
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['schedule_id']][] = $row;
        }
        return $grouped;
    }
}
