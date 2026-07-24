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

    /**
     * One row per journal line (not per transaction) in date order, for a
     * true General Journal report -- distinct from paginated(), which
     * collapses each transaction to a single row with a summed Amount.
     */
    public function journalLines(string $fromDate, string $toDate, string $status = '', string $search = ''): array
    {
        $sql = "SELECT je.id AS journal_id, je.journal_no, je.journal_date, je.reference_no, je.status,
                       jl.debit, jl.credit, aa.account_code, aa.account_name
                FROM accounting_journal_lines jl
                JOIN accounting_journal_entries je ON je.id = jl.journal_id
                JOIN accounting_accounts aa ON aa.id = jl.account_id
                WHERE je.journal_date BETWEEN ? AND ?";
        $params = [$fromDate, $toDate];

        if ($status !== '') {
            $sql .= " AND je.status = ?";
            $params[] = $status;
        }

        if ($search !== '') {
            $sql .= " AND (je.journal_no LIKE ? OR je.reference_no LIKE ? OR je.description LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }

        $sql .= " ORDER BY je.journal_date, je.id, jl.id";

        return $this->all($sql, $params);
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
     * Trial balance grouped by account type (Assets/Liabilities/Equity/
     * Income/Expenses) with a subtotal per group, plus a computed
     * "Owner's Capital / Retained Earnings" line under Equity when Assets
     * doesn't already reconcile to Liabilities + Equity + Net Income.
     *
     * This is a DISPLAY-ONLY figure, never a posted journal entry. Because
     * every journal is validated balanced at post time (AccountingJournal::
     * post()), the trial balance's raw Debit total always equals its Credit
     * total already -- that identity is algebraically the same statement as
     * "Assets = Liabilities + Equity + Net Income" once accounts are grouped
     * by their normal side. So on a fully consistent ledger this computed
     * line will correctly come out to ~0.00 and simply won't show -- it only
     * appears (as intended) if something ever violates that consistency,
     * e.g. an account with real posted history getting deactivated and
     * silently dropped from trialBalance()'s active-only account list.
     */
    public function trialBalanceGrouped(string $asOfDate): array
    {
        $rows = $this->trialBalance($asOfDate);

        $groupTypes = [
            'Assets' => ['Asset', 'Contra Asset'],
            'Liabilities' => ['Liability'],
            'Equity' => ['Equity'],
            'Income' => ['Income'],
            'Expenses' => ['Expense'],
        ];

        $groups = [];
        foreach ($groupTypes as $label => $types) {
            $groupRows = array_values(array_filter($rows, fn ($r) => in_array($r['account_type'], $types, true)));
            $groups[$label] = [
                'rows' => $groupRows,
                'debit_total' => round(array_sum(array_column($groupRows, 'debit_balance')), 2),
                'credit_total' => round(array_sum(array_column($groupRows, 'credit_balance')), 2),
            ];
        }

        $assetsNet = $groups['Assets']['debit_total'] - $groups['Assets']['credit_total'];
        $liabilitiesNet = $groups['Liabilities']['credit_total'] - $groups['Liabilities']['debit_total'];
        $equityNet = $groups['Equity']['credit_total'] - $groups['Equity']['debit_total'];
        $incomeNet = $groups['Income']['credit_total'] - $groups['Income']['debit_total'];
        $expenseNet = $groups['Expenses']['debit_total'] - $groups['Expenses']['credit_total'];
        $netIncome = $incomeNet - $expenseNet;

        $plug = round($assetsNet - $liabilitiesNet - $equityNet - $netIncome, 2);

        if (abs($plug) > 0.01) {
            $plugDebit = $plug < 0 ? abs($plug) : 0.0;
            $plugCredit = $plug > 0 ? $plug : 0.0;
            $groups['Equity']['rows'][] = [
                'account_code' => null,
                'account_name' => "Owner's Capital / Retained Earnings (computed)",
                'account_type' => 'Equity',
                'debit_balance' => $plugDebit,
                'credit_balance' => $plugCredit,
                'is_computed' => true,
            ];
            $groups['Equity']['debit_total'] = round($groups['Equity']['debit_total'] + $plugDebit, 2);
            $groups['Equity']['credit_total'] = round($groups['Equity']['credit_total'] + $plugCredit, 2);
        }

        return [
            'groups' => $groups,
            'grand_total_debit' => round(array_sum(array_column($groups, 'debit_total')), 2),
            'grand_total_credit' => round(array_sum(array_column($groups, 'credit_total')), 2),
        ];
    }

    /**
     * Net credits to a single GL account, grouped by month -- the GL-posting
     * basis for "Interest Income" on the MLR Summarised Management Report,
     * mirroring RegulatoryReportService::namfisaLevySummary()'s trend query.
     */
    public function accountCreditsByMonth(int $accountId, string $fromDate, string $toDate): array
    {
        return $this->all(
            "SELECT DATE_FORMAT(je.journal_date, '%Y-%m') AS month_key,
                    DATE_FORMAT(je.journal_date, '%M %Y') AS month_label,
                    COALESCE(SUM(jl.credit - jl.debit), 0) AS total_amount
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE jl.account_id = ? AND je.status = 'Posted' AND je.journal_date BETWEEN ? AND ?
             GROUP BY month_key, month_label
             ORDER BY month_key",
            [$accountId, $fromDate, $toDate]
        );
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
            "SELECT jl.debit, jl.credit, jl.description, je.id AS journal_id, je.journal_no, je.journal_date, je.reference_no, je.source_module
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
