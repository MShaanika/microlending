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
     * $leg distinguishes a split debit order's two independent legs, which
     * otherwise share the same (debit_order_id, installment_no) and would
     * look like duplicates of each other -- null for every non-split
     * collection, matching today's behaviour exactly.
     */
    public function alreadyPosted(int $debitOrderId, int $installmentNo, ?string $leg = null): bool
    {
        if ($leg === null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM debit_order_collections WHERE debit_order_id = ? AND installment_no = ? AND leg IS NULL AND payment_id IS NOT NULL LIMIT 1",
                [$debitOrderId, $installmentNo]
            );
        }
        return (bool) $this->scalar(
            "SELECT 1 FROM debit_order_collections WHERE debit_order_id = ? AND installment_no = ? AND leg = ? AND payment_id IS NOT NULL LIMIT 1",
            [$debitOrderId, $installmentNo, $leg]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_collections', $data);
    }
}
