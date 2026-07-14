<?php

namespace App\Models;

use App\Core\Model;

class DebitOrderCollection extends Model
{
    public function forImport(int $importId): array
    {
        return $this->all(
            "SELECT c.*, l.loan_no FROM debit_order_collections c
             LEFT JOIN loans l ON l.id = c.loan_id
             WHERE c.import_id = ? ORDER BY c.id",
            [$importId]
        );
    }

    /**
     * Guards against double-posting the same installment if the same
     * Collexia report (or an overlapping later one) is imported again.
     */
    public function alreadyPosted(int $debitOrderId, int $installmentNo): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM debit_order_collections WHERE debit_order_id = ? AND installment_no = ? AND payment_id IS NOT NULL LIMIT 1",
            [$debitOrderId, $installmentNo]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_collections', $data);
    }
}
