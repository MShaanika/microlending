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
            "SELECT d.*, l.loan_no, b.id_number, b.phone, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
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
     * Active mandates on this branch's loans that have never been
     * registered with Collexia yet -- registration is a one-time EnDo Batch
     * submission per contract (Collexia then collects every period on its
     * own), so this is not month-scoped the way the old bank-CSV workflow
     * was.
     */
    public function unregistered(int $branchId): array
    {
        return $this->all(
            "SELECT d.*, l.loan_no, l.branch_id AS loan_branch_id, l.payment_day,
                    b.first_name, b.last_name, b.id_number, b.phone,
                    CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM debit_orders d
             JOIN loans l ON l.id = d.loan_id
             JOIN borrowers b ON b.id = d.borrower_id
             WHERE d.status = 'Active'
               AND d.collexia_status = 'Not Registered'
               AND l.loan_status IN ('Active', 'Current')
               AND l.branch_id = ?
             ORDER BY d.debit_day, d.id",
            [$branchId]
        );
    }

    public function findByContractNo(string $contractNo): ?array
    {
        return $this->one(
            "SELECT d.*, l.loan_no FROM debit_orders d JOIN loans l ON l.id = d.loan_id WHERE d.merchant_system_contract_no = ?",
            [$contractNo]
        );
    }

    public function remainingInstallments(int $loanId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'", [$loanId]);
    }

    public function nextCollectionDate(int $loanId): ?string
    {
        $date = $this->scalar("SELECT MIN(due_date) FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'", [$loanId]);
        return $date ?: null;
    }

    public function markRegistered(int $id): bool
    {
        return $this->update('debit_orders', ['collexia_status' => 'Registered'], 'id', $id);
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
