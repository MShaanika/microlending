<?php

namespace App\Models;

use App\Core\Model;

class BadDebt extends Model
{
    public function findByLoan(int $loanId): ?array
    {
        return $this->one("SELECT * FROM bad_debts WHERE loan_id = ? AND status NOT IN ('Written Off','Recovered','Closed') ORDER BY id DESC LIMIT 1", [$loanId]);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT bd.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM bad_debts bd
             JOIN loans l ON l.id = bd.loan_id
             JOIN borrowers b ON b.id = bd.borrower_id
             WHERE bd.id = ?",
            [$id]
        );
    }

    public function paginated(string $status = ''): array
    {
        $sql = "SELECT bd.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM bad_debts bd
                JOIN loans l ON l.id = bd.loan_id
                JOIN borrowers b ON b.id = bd.borrower_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND bd.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY bd.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function create(array $data): int
    {
        return $this->insert('bad_debts', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('bad_debts', $data, 'id', $id);
    }
}
