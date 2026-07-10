<?php

namespace App\Models;

use App\Core\Model;

class BankAccount extends Model
{
    public function allBankAccounts(bool $activeOnly = false): array
    {
        $sql = "SELECT ba.*, aa.account_code AS gl_account_code, aa.account_name AS gl_account_name
                FROM accounting_bank_accounts ba
                JOIN accounting_accounts aa ON aa.id = ba.account_id";
        if ($activeOnly) {
            $sql .= " WHERE ba.is_active = 1";
        }
        $sql .= " ORDER BY ba.bank_name, ba.account_name";

        return $this->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT ba.*, aa.account_code AS gl_account_code, aa.account_name AS gl_account_name
             FROM accounting_bank_accounts ba
             JOIN accounting_accounts aa ON aa.id = ba.account_id
             WHERE ba.id = ?",
            [$id]
        );
    }

    public function accountNumberExists(string $accountNumber, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM accounting_bank_accounts WHERE account_number = ? AND id != ?", [$accountNumber, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM accounting_bank_accounts WHERE account_number = ?", [$accountNumber]);
    }

    public function create(array $data): int
    {
        return $this->insert('accounting_bank_accounts', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('accounting_bank_accounts', $data, 'id', $id);
    }
}
