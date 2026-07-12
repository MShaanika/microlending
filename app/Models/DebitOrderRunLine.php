<?php

namespace App\Models;

use App\Core\Model;

class DebitOrderRunLine extends Model
{
    public function forRun(int $runId): array
    {
        return $this->all(
            "SELECT rl.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM debit_order_run_lines rl
             JOIN loans l ON l.id = rl.loan_id
             JOIN borrowers b ON b.id = rl.borrower_id
             WHERE rl.run_id = ? ORDER BY rl.id",
            [$runId]
        );
    }

    public function findByReference(int $runId, string $bankReference): ?array
    {
        return $this->one("SELECT * FROM debit_order_run_lines WHERE run_id = ? AND bank_reference = ?", [$runId, $bankReference]);
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_run_lines', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_order_run_lines', $data, 'id', $id);
    }

    public function pendingCount(int $runId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM debit_order_run_lines WHERE run_id = ? AND status = 'Pending'", [$runId]);
    }

    public function unpostedSuccessful(int $runId): array
    {
        return $this->all(
            "SELECT rl.*, l.loan_no FROM debit_order_run_lines rl
             JOIN loans l ON l.id = rl.loan_id
             WHERE rl.run_id = ? AND rl.status = 'Successful' AND rl.payment_id IS NULL",
            [$runId]
        );
    }
}
