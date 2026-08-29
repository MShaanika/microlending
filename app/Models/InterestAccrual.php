<?php

namespace App\Models;

use App\Core\Model;

class InterestAccrual extends Model
{
    public function create(array $data): int
    {
        return $this->insert('interest_accruals', $data);
    }

    public function runsPaginated(): array
    {
        return $this->all(
            "SELECT accrual_date, COUNT(*) AS installment_count, SUM(amount) AS total_interest
             FROM interest_accruals
             GROUP BY accrual_date
             ORDER BY accrual_date DESC
             LIMIT 100"
        );
    }

    public function forRun(string $accrualDate): array
    {
        return $this->all(
            "SELECT ia.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, u.name AS accrued_by_name
             FROM interest_accruals ia
             JOIN loans l ON l.id = ia.loan_id
             JOIN borrowers b ON b.id = ia.borrower_id
             LEFT JOIN users u ON u.id = ia.accrued_by
             WHERE ia.accrual_date = ?
             ORDER BY ia.amount DESC",
            [$accrualDate]
        );
    }

    /**
     * Interest Receivable currently sitting on the books for this loan --
     * unlike penalty_due (only ever set by the accrual run), interest_due
     * exists on every loan_schedules row regardless of whether it's been
     * accrued yet, so this is gated to rows that actually have an
     * interest_accruals entry -- otherwise it would overstate the
     * receivable with not-yet-earned future interest that was never
     * booked to 1030 in the first place.
     */
    public function outstandingForLoan(int $loanId): float
    {
        return (float) ($this->scalar(
            "SELECT COALESCE(SUM(ls.interest_due - ls.interest_paid), 0)
             FROM loan_schedules ls
             JOIN interest_accruals ia ON ia.schedule_id = ls.id AND ia.status = 'Accrued'
             WHERE ls.loan_id = ?",
            [$loanId]
        ) ?: 0);
    }

    public function findByScheduleId(int $scheduleId): ?array
    {
        return $this->one("SELECT * FROM interest_accruals WHERE schedule_id = ?", [$scheduleId]);
    }
}
