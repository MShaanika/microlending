<?php

namespace App\Models;

use App\Core\Model;

class JournalEntry extends Model
{
    public function paginated(string $search = '', string $sourceModule = '', int $limit = 200): array
    {
        $sql = "SELECT je.*,
                       (SELECT COALESCE(SUM(debit),0) FROM accounting_journal_lines WHERE journal_id = je.id) AS total_debit
                FROM accounting_journal_entries je
                WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (je.journal_no LIKE ? OR je.reference_no LIKE ? OR je.description LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        if ($sourceModule !== '') {
            $sql .= " AND je.source_module = ?";
            $params[] = $sourceModule;
        }

        $sql .= " ORDER BY je.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM accounting_journal_entries WHERE id = ?", [$id]);
    }

    public function lines(int $journalId): array
    {
        return $this->all(
            "SELECT jl.*, aa.account_code, aa.account_name
             FROM accounting_journal_lines jl
             JOIN accounting_accounts aa ON aa.id = jl.account_id
             WHERE jl.journal_id = ?
             ORDER BY jl.id",
            [$journalId]
        );
    }

    public function sourceModules(): array
    {
        return array_column(
            $this->all("SELECT DISTINCT source_module FROM accounting_journal_entries WHERE source_module IS NOT NULL ORDER BY source_module"),
            'source_module'
        );
    }

    /**
     * Running balance for a GL account as of today (debit-normal accounts
     * are positive on debit, credit-normal accounts positive on credit).
     */
    public function accountBalance(int $accountId, string $normalBalance): float
    {
        $row = $this->one(
            "SELECT COALESCE(SUM(jl.debit),0) AS debit, COALESCE(SUM(jl.credit),0) AS credit
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE jl.account_id = ? AND je.status = 'Posted'",
            [$accountId]
        );

        $debit = (float) ($row['debit'] ?? 0);
        $credit = (float) ($row['credit'] ?? 0);

        return $normalBalance === 'Credit' ? round($credit - $debit, 2) : round($debit - $credit, 2);
    }

    /**
     * One row per account with non-zero activity as of a date, in trial
     * balance format: the net balance appears in the Debit column or the
     * Credit column depending on its actual sign (not its "normal" side),
     * so a healthy trial balance always has matching Debit/Credit totals.
     */
    public function trialBalance(string $asOfDate): array
    {
        $rows = $this->all(
            "SELECT aa.id, aa.account_code, aa.account_name, aa.account_type,
                    COALESCE(SUM(jl.debit),0) AS total_debit,
                    COALESCE(SUM(jl.credit),0) AS total_credit
             FROM accounting_accounts aa
             LEFT JOIN accounting_journal_lines jl ON jl.account_id = aa.id
             LEFT JOIN accounting_journal_entries je ON je.id = jl.journal_id AND je.status = 'Posted' AND je.journal_date <= ?
             WHERE aa.is_active = 1
             GROUP BY aa.id
             HAVING total_debit > 0 OR total_credit > 0
             ORDER BY aa.account_code",
            [$asOfDate]
        );

        foreach ($rows as &$row) {
            $net = round((float) $row['total_debit'] - (float) $row['total_credit'], 2);
            $row['debit_balance'] = $net > 0 ? $net : 0;
            $row['credit_balance'] = $net < 0 ? abs($net) : 0;
        }

        return $rows;
    }

    /**
     * All posted journal lines touching a single GL account within a date
     * range, in date order, with a running balance seeded from whatever
     * activity happened before $fromDate.
     */
    public function cashBook(int $accountId, string $fromDate, string $toDate): array
    {
        $openingRow = $this->one(
            "SELECT COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0) AS balance
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE jl.account_id = ? AND je.status = 'Posted' AND je.journal_date < ?",
            [$accountId, $fromDate]
        );
        $balance = round((float) ($openingRow['balance'] ?? 0), 2);

        $lines = $this->all(
            "SELECT jl.debit, jl.credit, jl.description, je.journal_no, je.journal_date, je.reference_no, je.source_module
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE jl.account_id = ? AND je.status = 'Posted' AND je.journal_date BETWEEN ? AND ?
             ORDER BY je.journal_date, je.id",
            [$accountId, $fromDate, $toDate]
        );

        $openingBalance = $balance;
        foreach ($lines as &$line) {
            $balance = round($balance + (float) $line['debit'] - (float) $line['credit'], 2);
            $line['running_balance'] = $balance;
        }

        return ['opening_balance' => $openingBalance, 'closing_balance' => $balance, 'lines' => $lines];
    }
}
