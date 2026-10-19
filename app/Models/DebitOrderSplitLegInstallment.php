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
    public function add(int $legId, int $installmentNo, int $scheduleId, string $plannedDate, float $plannedAmount): int
    {
        return $this->insert('debit_order_split_leg_installments', [
            'leg_id' => $legId,
            'installment_no' => $installmentNo,
            'schedule_id' => $scheduleId,
            'planned_date' => $plannedDate,
            'planned_amount' => $plannedAmount,
        ]);
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
                    li.planned_date, li.collexia_date, li.planned_amount AS amount, li.applied_at
             FROM debit_order_split_leg_installments li
             JOIN debit_order_split_legs sl ON sl.id = li.leg_id
             WHERE sl.debit_order_id = ? AND sl.merged_into_id IS NULL
             UNION ALL
             SELECT sl.schedule_id, sl.id, sl.split_no, sl.total_splits, sl.collexia_api_contract_reference,
                    sl.collexia_api_status, 1, sl.collection_date, NULL, sl.leg_amount, NULL
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
