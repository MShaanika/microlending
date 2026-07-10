<?php

namespace App\Models;

use App\Core\Model;

class Penalty extends Model
{
    public function create(array $data): int
    {
        return $this->insert('penalties', $data);
    }

    public function runsPaginated(): array
    {
        return $this->all(
            "SELECT penalty_date, COUNT(*) AS installment_count, SUM(penalty_amount) AS total_penalty
             FROM penalties
             GROUP BY penalty_date
             ORDER BY penalty_date DESC
             LIMIT 100"
        );
    }

    public function forRun(string $penaltyDate): array
    {
        return $this->all(
            "SELECT p.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM penalties p
             JOIN loans l ON l.id = p.loan_id
             JOIN borrowers b ON b.id = p.borrower_id
             WHERE p.penalty_date = ?
             ORDER BY p.penalty_amount DESC",
            [$penaltyDate]
        );
    }

    public function outstandingForLoan(int $loanId): float
    {
        // Penalty Receivable currently sitting on the books for this loan --
        // penalty_due is only ever set by the accrual run, so this always
        // matches the GL 1040 balance attributable to this loan.
        return (float) ($this->scalar(
            "SELECT COALESCE(SUM(penalty_due - penalty_paid), 0) FROM loan_schedules WHERE loan_id = ?",
            [$loanId]
        ) ?: 0);
    }

    public function markPaidWhereSettled(int $loanId): void
    {
        $this->query(
            "UPDATE penalties p
             JOIN loan_schedules ls ON ls.id = p.schedule_id
             SET p.status = 'Paid'
             WHERE p.loan_id = ? AND p.status = 'Charged' AND ls.penalty_paid >= ls.penalty_due AND ls.penalty_due > 0",
            [$loanId]
        );
    }
}
