<?php

namespace App\Models;

use App\Core\Model;

/**
 * Read/list helpers for "Adjustment Journals" -- manual one-off fixes,
 * always exactly one debit line + one credit line. Reuses the same
 * accounting_journal_entries/accounting_journal_lines tables as every other
 * posting (journal_type = 'Adjustment' is what distinguishes these rows),
 * so there's a single source of truth for the GL. Creation/editing/posting
 * goes through AccountingJournal::saveDraft()/updateDraft()/postDraft()/
 * post(); reversal through AccountingJournal::reverse() -- this model is
 * read-only.
 */
class AdjustmentJournal extends Model
{
    /**
     * One row per journal (not per line), with the debit/credit account
     * and amount resolved from its 2 lines -- matches the table shape the
     * Adjustment Journals list page shows.
     */
    public function paginated(array $filters): array
    {
        $sql = "SELECT je.id, je.journal_no, je.journal_date, je.description, je.status, je.reference_no,
                       MAX(CASE WHEN jl.debit > 0 THEN CONCAT(aa.account_code, ' - ', aa.account_name) END) AS debit_account,
                       MAX(CASE WHEN jl.credit > 0 THEN CONCAT(aa.account_code, ' - ', aa.account_name) END) AS credit_account,
                       SUM(jl.debit) AS debit_amount,
                       SUM(jl.credit) AS credit_amount
                FROM accounting_journal_entries je
                JOIN accounting_journal_lines jl ON jl.journal_id = je.id
                JOIN accounting_accounts aa ON aa.id = jl.account_id
                WHERE je.journal_type = 'Adjustment'";
        $params = [];

        if (!empty($filters['from_date'])) {
            $sql .= " AND je.journal_date >= ?";
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND je.journal_date <= ?";
            $params[] = $filters['to_date'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND je.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['account_id'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM accounting_journal_lines jl2 WHERE jl2.journal_id = je.id AND jl2.account_id = ?)";
            $params[] = $filters['account_id'];
        }

        $sql .= " GROUP BY je.id ORDER BY je.journal_date DESC, je.id DESC";

        return $this->all($sql, $params);
    }
}
