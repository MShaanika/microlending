<?php

namespace App\Models;

use App\Core\Model;

class DebitOrderCancellation extends Model
{
    public function paginated(string $status = '', ?int $branchId = null): array
    {
        $sql = "SELECT c.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.branch_id AS borrower_branch_id, l.loan_no
                FROM debit_order_cancellations c
                JOIN borrowers b ON b.id = c.borrower_id
                LEFT JOIN loans l ON l.id = c.loan_id
                WHERE 1=1";
        $params = [];
        if ($status !== '') {
            $sql .= " AND c.status = ?";
            $params[] = $status;
        }
        if ($branchId !== null) {
            $sql .= " AND b.branch_id = ?";
            $params[] = $branchId;
        }
        $sql .= " ORDER BY c.id DESC LIMIT 200";
        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT c.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, b.branch_id AS borrower_branch_id, l.loan_no,
                    rqu.name AS requested_by_name, au.name AS approved_by_name
             FROM debit_order_cancellations c
             JOIN borrowers b ON b.id = c.borrower_id
             LEFT JOIN loans l ON l.id = c.loan_id
             LEFT JOIN users rqu ON rqu.id = c.requested_by
             LEFT JOIN users au ON au.id = c.approved_by
             WHERE c.id = ?",
            [$id]
        );
    }

    public function findPendingForDebitOrder(int $debitOrderId): ?array
    {
        return $this->one(
            "SELECT * FROM debit_order_cancellations WHERE debit_order_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1",
            [$debitOrderId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('debit_order_cancellations', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('debit_order_cancellations', $data, 'id', $id);
    }
}
