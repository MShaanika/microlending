<?php

namespace App\Models;

use App\Core\Model;

class DebitOrder extends Model
{
    public function paginated(string $status = ''): array
    {
        $sql = "SELECT d.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM debit_orders d
                JOIN loans l ON l.id = d.loan_id
                JOIN borrowers b ON b.id = d.borrower_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND d.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY d.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT d.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM debit_orders d
             JOIN loans l ON l.id = d.loan_id
             JOIN borrowers b ON b.id = d.borrower_id
             WHERE d.id = ?",
            [$id]
        );
    }

    public function forLoan(int $loanId): array
    {
        return $this->all("SELECT * FROM debit_orders WHERE loan_id = ? ORDER BY id DESC", [$loanId]);
    }

    /**
     * Every Active mandate due for collection in a given month: within its
     * start/end window, and on a loan that's still actually being
     * collected against (a written-off or completed loan shouldn't be
     * swept into a new run just because its old mandate is still marked
     * Active).
     */
    public function activeForMonth(int $branchId, string $yearMonth): array
    {
        $monthStart = $yearMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        return $this->all(
            "SELECT d.*, l.loan_no, l.branch_id AS loan_branch_id, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM debit_orders d
             JOIN loans l ON l.id = d.loan_id
             JOIN borrowers b ON b.id = d.borrower_id
             WHERE d.status = 'Active'
               AND l.loan_status IN ('Active', 'Current')
               AND l.branch_id = ?
               AND d.start_date <= ?
               AND (d.end_date IS NULL OR d.end_date >= ?)
             ORDER BY d.debit_day, d.id",
            [$branchId, $monthEnd, $monthStart]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_orders', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_orders', $data, 'id', $id);
    }
}
