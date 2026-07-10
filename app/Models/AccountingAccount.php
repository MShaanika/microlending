<?php

namespace App\Models;

use App\Core\Model;

class AccountingAccount extends Model
{
    private static array $cache = [];

    public function idByCode(string $code): int
    {
        if (isset(self::$cache[$code])) {
            return self::$cache[$code];
        }

        $id = $this->scalar("SELECT id FROM accounting_accounts WHERE account_code = ? LIMIT 1", [$code]);
        if (!$id) {
            throw new \RuntimeException("Missing accounting account: $code");
        }

        self::$cache[$code] = (int) $id;
        return (int) $id;
    }

    public function allAccounts(bool $activeOnly = false): array
    {
        $sql = "SELECT a.*, p.account_name AS parent_account_name
                FROM accounting_accounts a
                LEFT JOIN accounting_accounts p ON p.id = a.parent_account_id";
        if ($activeOnly) {
            $sql .= " WHERE a.is_active = 1";
        }
        $sql .= " ORDER BY a.account_code";

        return $this->all($sql);
    }

    /**
     * Accounts flagged as an actual bank/cash account -- the pool a new
     * accounting_bank_accounts row can link to.
     */
    public function cashBankAccounts(): array
    {
        return $this->all(
            "SELECT * FROM accounting_accounts WHERE is_cash_bank_account = 1 AND is_active = 1 ORDER BY account_code"
        );
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM accounting_accounts WHERE id = ?", [$id]);
    }

    public function codeExists(string $code, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            return (bool) $this->scalar("SELECT 1 FROM accounting_accounts WHERE account_code = ? AND id != ?", [$code, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM accounting_accounts WHERE account_code = ?", [$code]);
    }

    public function create(array $data): int
    {
        return $this->insert('accounting_accounts', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('accounting_accounts', $data, 'id', $id);
    }
}
