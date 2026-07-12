<?php

namespace App\Models;

use App\Core\Model;

class Expense extends Model
{
    public function paginated(string $status = '', int $categoryId = 0): array
    {
        $sql = "SELECT e.*, c.category_name, br.branch_name
                FROM expenses e
                JOIN expense_categories c ON c.id = e.category_id
                JOIN branches br ON br.id = e.branch_id
                WHERE 1=1";
        $params = [];

        if ($status !== '') {
            $sql .= " AND e.status = ?";
            $params[] = $status;
        }
        if ($categoryId > 0) {
            $sql .= " AND e.category_id = ?";
            $params[] = $categoryId;
        }

        $sql .= " ORDER BY e.id DESC LIMIT 200";

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT e.*, c.category_name, c.account_id AS category_account_id, br.branch_name
             FROM expenses e
             JOIN expense_categories c ON c.id = e.category_id
             JOIN branches br ON br.id = e.branch_id
             WHERE e.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('expenses', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('expenses', $data, 'id', $id);
    }

    public function addAttachment(array $data): int
    {
        return $this->insert('expense_attachments', $data);
    }

    public function attachmentsFor(int $expenseId): array
    {
        return $this->all("SELECT * FROM expense_attachments WHERE expense_id = ? ORDER BY id", [$expenseId]);
    }

    public function findAttachment(int $expenseId, int $attachmentId): ?array
    {
        return $this->one("SELECT * FROM expense_attachments WHERE id = ? AND expense_id = ?", [$attachmentId, $expenseId]);
    }

    public function addApproval(array $data): int
    {
        return $this->insert('expense_approvals', $data);
    }

    public function approvalsFor(int $expenseId): array
    {
        return $this->all(
            "SELECT ea.*, u.name AS approver_name FROM expense_approvals ea
             LEFT JOIN users u ON u.id = ea.approver_id
             WHERE ea.expense_id = ? ORDER BY ea.id",
            [$expenseId]
        );
    }
}
