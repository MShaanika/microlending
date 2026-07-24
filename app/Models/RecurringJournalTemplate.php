<?php

namespace App\Models;

use App\Core\Model;

class RecurringJournalTemplate extends Model
{
    public function paginated(string $status = ''): array
    {
        $sql = "SELECT t.*, da.account_code AS debit_account_code, da.account_name AS debit_account_name,
                       ca.account_code AS credit_account_code, ca.account_name AS credit_account_name
                FROM recurring_journal_templates t
                JOIN accounting_accounts da ON da.id = t.debit_account_id
                JOIN accounting_accounts ca ON ca.id = t.credit_account_id";
        $params = [];
        if ($status !== '') {
            $sql .= " WHERE t.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY t.next_run_date ASC, t.id DESC";

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT t.*, da.account_code AS debit_account_code, da.account_name AS debit_account_name,
                    ca.account_code AS credit_account_code, ca.account_name AS credit_account_name
             FROM recurring_journal_templates t
             JOIN accounting_accounts da ON da.id = t.debit_account_id
             JOIN accounting_accounts ca ON ca.id = t.credit_account_id
             WHERE t.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('recurring_journal_templates', $data);
    }

    public function updateFields(int $id, array $data): bool
    {
        return $this->update('recurring_journal_templates', $data, 'id', $id);
    }

    public function delete(int $id): void
    {
        $this->query("DELETE FROM recurring_journal_templates WHERE id = ?", [$id]);
    }

    /**
     * Active templates whose next_run_date has arrived (or was missed, e.g.
     * the daily job didn't run one day) and haven't passed their end_date.
     */
    public function dueOn(string $date): array
    {
        return $this->all(
            "SELECT * FROM recurring_journal_templates
             WHERE status = 'Active' AND next_run_date <= ? AND (end_date IS NULL OR end_date >= next_run_date)
             ORDER BY next_run_date ASC",
            [$date]
        );
    }

    /**
     * Journal entries this template has generated so far, most recent first.
     */
    public function generatedJournals(int $id): array
    {
        return $this->all(
            "SELECT * FROM accounting_journal_entries
             WHERE source_table = 'recurring_journal_templates' AND source_id = ?
             ORDER BY journal_date DESC, id DESC",
            [$id]
        );
    }
}
