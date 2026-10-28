<?php

namespace App\Models;

use App\Core\Model;

/**
 * The loan_schedules rows (on the original loan and on its top-ups) that ONE combined Collexia instalment settles -- see
 * database/debit_order_combined_parts.sql. A mandate that never combined anything has no rows here.
 */
class DebitOrderSplitLegInstallmentPart extends Model
{
    public function add(int $installmentId, int $loanId, int $scheduleId, float $plannedAmount): int
    {
        return $this->insert('debit_order_split_leg_installment_parts', [
            'installment_id' => $installmentId,
            'loan_id' => $loanId,
            'schedule_id' => $scheduleId,
            'planned_amount' => $plannedAmount,
        ]);
    }

    /** @return array<int, array{loan_id: int|string, schedule_id: int|string, planned_amount: string}> the original loan's row first, then top-ups by loan id */
    public function forInstallment(int $installmentId): array
    {
        return $this->all(
            "SELECT p.loan_id, p.schedule_id, p.planned_amount
             FROM debit_order_split_leg_installment_parts p
             JOIN loans l ON l.id = p.loan_id
             WHERE p.installment_id = ?
             ORDER BY (l.topup_of_loan_id IS NOT NULL), p.loan_id",
            [$installmentId]
        );
    }

    /** The parts of the instalment a lane carries under Collexia's own installment number (empty for an ordinary instalment). */
    public function forLaneInstallment(int $legId, int $installmentNo): array
    {
        return $this->all(
            "SELECT p.loan_id, p.schedule_id, p.planned_amount
             FROM debit_order_split_leg_installment_parts p
             JOIN debit_order_split_leg_installments li ON li.id = p.installment_id
             JOIN loans l ON l.id = p.loan_id
             WHERE li.leg_id = ? AND li.installment_no = ?
             ORDER BY (l.topup_of_loan_id IS NOT NULL), p.loan_id",
            [$legId, $installmentNo]
        );
    }
}
