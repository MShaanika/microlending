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
            "SELECT bdp.*, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, u.name AS posted_by_name
             FROM bad_debt_provisions bdp
             JOIN loans l ON l.id = bdp.loan_id
             JOIN borrowers b ON b.id = bdp.borrower_id
             LEFT JOIN users u ON u.id = bdp.posted_by
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

    /**
     * Loans whose most recent Posted provision snapshot is still nonzero --
     * used by BadDebtProvisionController::computeRun() to find loans that
     * have cured (dropped out of the current overdue set) but still need an
     * explicit provision_amount=0 row written, otherwise provisionForLoan()
     * would keep returning their stale last-nonzero snapshot forever and
     * credit_status could never revert from 'Impaired'.
     */
    public function loanIdsWithNonzeroPostedProvision(): array
    {
        $rows = $this->all(
            "SELECT bp.loan_id
             FROM bad_debt_provisions bp
             INNER JOIN (
                 SELECT loan_id, MAX(id) AS max_id
                 FROM bad_debt_provisions
                 WHERE status = 'Posted'
                 GROUP BY loan_id
             ) latest ON latest.max_id = bp.id
             WHERE bp.provision_amount > 0.009"
        );
        return array_map('intval', array_column($rows, 'loan_id'));
    }
}
