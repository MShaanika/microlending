<?php

namespace App\Models;

use App\Core\Model;

class HrmPayroll extends Model
{
    public function allPayrolls(): array
    {
        return $this->query(
            "SELECT p.*, b.bank_name, b.account_name
             FROM hrm_payrolls p
             LEFT JOIN accounting_bank_accounts b ON b.id = p.bank_account_id
             ORDER BY p.pay_period_start DESC"
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT p.*, b.bank_name, b.account_name
             FROM hrm_payrolls p
             LEFT JOIN accounting_bank_accounts b ON b.id = p.bank_account_id
             WHERE p.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_payrolls', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_payrolls', $data, 'id', $id);
    }
}
