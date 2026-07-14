<?php

namespace App\Models;

use App\Core\Model;

class LoanReschedule extends Model
{
    public function paginated(string $status = ''): array
    {
        $sql = "SELECT r.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM loan_reschedules r
                JOIN loans l ON l.id = r.loan_id
                JOIN borrowers b ON b.id = r.borrower_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND r.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY r.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT r.*, l.loan_no, l.loan_status, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM loan_reschedules r
             JOIN loans l ON l.id = r.loan_id
             JOIN borrowers b ON b.id = r.borrower_id
             WHERE r.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('loan_reschedules', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('loan_reschedules', $data, 'id', $id);
    }

    /** A loan that has ever had a reschedule implemented against it can no
     *  longer be topped up -- staff must use Reschedule again instead. */
    public function hasImplementedReschedule(int $loanId): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM loan_reschedules WHERE loan_id = ? AND status = 'Implemented' LIMIT 1",
            [$loanId]
        );
    }
}
