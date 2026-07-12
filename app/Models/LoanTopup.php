<?php

namespace App\Models;

use App\Core\Model;

/**
 * Audit trail + undo source for TopUpService::consolidate(): one row per
 * consolidation event, snapshotting the loan/schedule/statutory-charge state
 * as it stood immediately before, so a mistaken top-up can be fully reversed.
 */
class LoanTopup extends Model
{
    public function create(array $data): int
    {
        return $this->insert('loan_topups', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM loan_topups WHERE id = ?", [$id]);
    }

    /**
     * The most recent unreversed consolidation for a loan, if any -- the
     * only one that may safely be reversed, since reversing an older layer
     * while a newer one sits on top of it would corrupt the loan's state.
     */
    public function latestActiveForLoan(int $loanId): ?array
    {
        return $this->one(
            "SELECT * FROM loan_topups WHERE loan_id = ? AND status = 'Active' ORDER BY id DESC LIMIT 1",
            [$loanId]
        );
    }

    public function markReversed(int $id, int $userId): bool
    {
        return $this->update('loan_topups', [
            'status' => 'Reversed',
            'reversed_by' => $userId,
            'reversed_at' => date('Y-m-d H:i:s'),
        ], 'id', $id);
    }
}
