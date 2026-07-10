<?php

namespace App\Models;

use App\Core\Model;

class BadDebtProvision extends Model
{
    public function create(array $data): int
    {
        return $this->insert('bad_debt_provisions', $data);
    }

    public function runsPaginated(): array
    {
        return $this->all(
            "SELECT provision_date, COUNT(*) AS loan_count, SUM(provision_amount) AS total_provision, journal_id, status
             FROM bad_debt_provisions
             GROUP BY provision_date, journal_id, status
             ORDER BY provision_date DESC
             LIMIT 100"
        );
    }

    public function forRun(string $provisionDate): array
    {
        return $this->all(
            "SELECT bdp.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
             FROM bad_debt_provisions bdp
             JOIN loans l ON l.id = bdp.loan_id
             JOIN borrowers b ON b.id = bdp.borrower_id
             WHERE bdp.provision_date = ?
             ORDER BY bdp.provision_amount DESC",
            [$provisionDate]
        );
    }

    public function currentProvisionBalance(): float
    {
        // The live balance of the Provision for Doubtful Debts control
        // account (contra-asset, credit normal) -- the figure any new
        // provisioning run must reconcile its delta against.
        return (float) ($this->scalar(
            "SELECT COALESCE(SUM(jl.credit) - SUM(jl.debit), 0)
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             JOIN accounting_accounts aa ON aa.id = jl.account_id
             WHERE aa.account_code = '1050' AND je.status = 'Posted'"
        ) ?: 0);
    }

    public function provisionForLoan(int $loanId): float
    {
        // Each run stores the loan's full required provision level as of
        // that run's date (not a delta) -- only the most recent posted
        // snapshot reflects what's actually held against this loan today.
        return (float) ($this->scalar(
            "SELECT provision_amount FROM bad_debt_provisions
             WHERE loan_id = ? AND status = 'Posted'
             ORDER BY provision_date DESC, id DESC LIMIT 1",
            [$loanId]
        ) ?: 0);
    }
}
