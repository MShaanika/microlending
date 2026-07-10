<?php

namespace App\Models;

use App\Core\Model;

class LoanRecovery extends Model
{
    public function forWriteOff(int $writeOffId): array
    {
        return $this->all(
            "SELECT * FROM loan_recoveries WHERE write_off_id = ? ORDER BY recovery_date DESC",
            [$writeOffId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('loan_recoveries', $data);
    }

    public function createAllocation(array $data): int
    {
        return $this->insert('loan_recovery_allocations', $data);
    }

    public function paginated(): array
    {
        return $this->all(
            "SELECT r.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM loan_recoveries r
             JOIN loans l ON l.id = r.loan_id
             JOIN borrowers b ON b.id = r.borrower_id
             ORDER BY r.id DESC LIMIT 200"
        );
    }
}
