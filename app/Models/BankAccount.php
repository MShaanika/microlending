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

    /**
     * The accounting_bank_accounts row for a given GL (chart of accounts)
     * account -- used to link a Cash Book screen (keyed by GL account) over
     * to Bank Reconciliation (keyed by bank account). Null if that GL
     * account has no formal bank account record (e.g. a petty cash account).
     */
    public function findByAccountId(int $glAccountId): ?array
    {
        return $this->one(
            "SELECT ba.*, aa.account_code AS gl_account_code, aa.account_name AS gl_account_name
             FROM accounting_bank_accounts ba
             JOIN accounting_accounts aa ON aa.id = ba.account_id
             WHERE ba.account_id = ? AND ba.is_active = 1
             LIMIT 1",
            [$glAccountId]
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
