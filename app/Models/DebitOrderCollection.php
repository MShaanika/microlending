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

    /** Every collection attempt recorded for a debit order (any split_no, or none) -- used to look up each split's actual collection date on the split-transactions drill-down. */
    public function forDebitOrder(int $debitOrderId): array
    {
        return $this->all("SELECT * FROM debit_order_collections WHERE debit_order_id = ? ORDER BY id", [$debitOrderId]);
    }

    /**
     * Guards against double-posting the same installment if the same
     * Collexia report (or an overlapping later one) is imported again.
     * $splitNo distinguishes a split debit order's independent split
     * transactions, which otherwise share the same (debit_order_id,
     * installment_no) and would look like duplicates of each other --
     * null for every non-split collection, matching today's behaviour
     * exactly.
     */
    public function alreadyPosted(int $debitOrderId, int $installmentNo, ?int $splitNo = null): bool
    {
        if ($splitNo === null) {
            return (bool) $this->scalar(
                "SELECT 1 FROM debit_order_collections WHERE debit_order_id = ? AND installment_no = ? AND split_no IS NULL AND payment_id IS NOT NULL LIMIT 1",
                [$debitOrderId, $installmentNo]
            );
        }
        return (bool) $this->scalar(
            "SELECT 1 FROM debit_order_collections WHERE debit_order_id = ? AND installment_no = ? AND split_no = ? AND payment_id IS NOT NULL LIMIT 1",
            [$debitOrderId, $installmentNo, $splitNo]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_collections', $data);
    }
}
