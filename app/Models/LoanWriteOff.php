<?php

namespace App\Models;

use App\Core\Model;

class LoanWriteOff extends Model
{
    public function paginated(string $status = ''): array
    {
        $sql = "SELECT wo.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM loan_write_offs wo
                JOIN loans l ON l.id = wo.loan_id
                JOIN borrowers b ON b.id = wo.borrower_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND wo.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY wo.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT wo.*, l.loan_no, l.loan_status, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM loan_write_offs wo
             JOIN loans l ON l.id = wo.loan_id
             JOIN borrowers b ON b.id = wo.borrower_id
             WHERE wo.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('loan_write_offs', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('loan_write_offs', $data, 'id', $id);
    }

    public function totalRecoveredFor(int $writeOffId): float
    {
        return (float) ($this->scalar(
            "SELECT COALESCE(SUM(recovered_amount),0) FROM loan_recoveries WHERE write_off_id = ? AND status = 'Posted'",
            [$writeOffId]
        ) ?: 0);
    }
}
