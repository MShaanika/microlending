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

    /**
     * Active categories mapped into the Annual Financial Statement
     * Analysis (afs_group set) -- powers the report's monthly detail grid
     * and the Core/Other/Excluded split of the quarterly summary.
     */
    public function afsCategories(): array
    {
        return $this->query(
            "SELECT c.id, c.category_name, c.afs_group, a.account_code
             FROM expense_categories c
             LEFT JOIN accounting_accounts a ON a.id = c.account_id
             WHERE c.is_active = 1 AND c.afs_group IS NOT NULL
             ORDER BY FIELD(c.afs_group, 'Core', 'Other', 'Excluded'), a.account_code"
        )->fetchAll();
    }
}
