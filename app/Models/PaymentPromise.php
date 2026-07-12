<?php

namespace App\Models;

use App\Core\Model;

class PaymentPromise extends Model
{
    public function forLoan(int $loanId): array
    {
        return $this->all(
            "SELECT pp.*, u.name AS created_by_name
             FROM payment_promises pp
             LEFT JOIN users u ON u.id = pp.created_by
             WHERE pp.loan_id = ? ORDER BY pp.promise_date DESC, pp.id DESC",
            [$loanId]
        );
    }

    public function latestPendingForLoan(int $loanId): ?array
    {
        return $this->one(
            "SELECT * FROM payment_promises WHERE loan_id = ? AND status = 'Pending' ORDER BY promise_date DESC LIMIT 1",
            [$loanId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM payment_promises WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('payment_promises', $data);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->update('payment_promises', ['status' => $status], 'id', $id);
    }
}
