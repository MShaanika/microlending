<?php

namespace App\Models;

use App\Core\Model;

class BankStatementLine extends Model
{
    public function create(array $data): int
    {
        return $this->insert('accounting_bank_statement', $data);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM accounting_bank_statement WHERE id = ?", [$id]);
    }

    public function unreconciled(int $bankAccountId): array
    {
        return $this->all(
            "SELECT * FROM accounting_bank_statement
             WHERE bank_account_id = ? AND reconciled = 0
             ORDER BY transaction_date, id",
            [$bankAccountId]
        );
    }

    public function forBankAccount(int $bankAccountId, int $limit = 300): array
    {
        return $this->query(
            "SELECT * FROM accounting_bank_statement WHERE bank_account_id = ? ORDER BY transaction_date DESC, id DESC LIMIT " . (int) $limit,
            [$bankAccountId]
        )->fetchAll();
    }

    public function markReconciled(int $id): bool
    {
        return $this->update('accounting_bank_statement', ['reconciled' => 1], 'id', $id);
    }

    public function markUnreconciled(int $id): bool
    {
        return $this->update('accounting_bank_statement', ['reconciled' => 0], 'id', $id);
    }

    public function referenceExists(int $bankAccountId, string $transactionDate, string $referenceNo, float $moneyIn, float $moneyOut): bool
    {
        return (bool) $this->scalar(
            "SELECT 1 FROM accounting_bank_statement
             WHERE bank_account_id = ? AND transaction_date = ? AND reference_no = ? AND money_in = ? AND money_out = ?
             LIMIT 1",
            [$bankAccountId, $transactionDate, $referenceNo, $moneyIn, $moneyOut]
        );
    }

    public function latestBalance(int $bankAccountId): ?float
    {
        $value = $this->scalar(
            "SELECT balance FROM accounting_bank_statement WHERE bank_account_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1",
            [$bankAccountId]
        );
        return $value === false || $value === null ? null : (float) $value;
    }
}
