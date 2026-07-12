<?php

namespace App\Models;

use App\Core\Model;

class ExpenseCategory extends Model
{
    public function allCategories(bool $activeOnly = false): array
    {
        $sql = "SELECT c.*, a.account_code, a.account_name
                FROM expense_categories c
                LEFT JOIN accounting_accounts a ON a.id = c.account_id";
        if ($activeOnly) {
            $sql .= " WHERE c.is_active = 1";
        }
        $sql .= " ORDER BY c.category_name";

        return $this->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT c.*, a.account_code, a.account_name
             FROM expense_categories c
             LEFT JOIN accounting_accounts a ON a.id = c.account_id
             WHERE c.id = ?",
            [$id]
        );
    }

    public function nameExists(string $name): bool
    {
        return (bool) $this->scalar("SELECT 1 FROM expense_categories WHERE category_name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('expense_categories', $data);
    }
}
