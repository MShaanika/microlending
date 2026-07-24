<?php

namespace App\Models;

use App\Core\Model;

/**
 * Shared double-entry journal posting. Every posting in the system (loan
 * disbursement, payment collection, depreciation, manual adjustments, ...)
 * goes through post() so balance validation, period assignment, and the
 * journal_no scheme stay in one place.
 */
class AccountingJournal extends Model
{
    /**
     * @param array<int, array{account_id:int, debit:float, credit:float, description?:string}> $lines
     */
    public function post(
        string $sourceModule,
        string $sourceTable,
        ?int $sourceId,
        string $referenceNo,
        string $description,
        array $lines,
        ?int $userId,
        ?string $journalDate = null,
        string $journalType = 'Automatic'
    ): int {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $totalDebit += (float) $line['debit'];
            $totalCredit += (float) $line['credit'];
        }

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new \RuntimeException("Journal not balanced. Debit: $totalDebit Credit: $totalCredit");
        }

        $journalDate = $journalDate ?: date('Y-m-d');

        $period = (new AccountingPeriod())->findByDate($journalDate);
        if ($period && (int) $period['is_closed'] === 1) {
            throw new \RuntimeException("Cannot post to {$journalDate}: accounting period \"{$period['period_name']}\" is closed.");
        }

        $journalId = $this->insert('accounting_journal_entries', [
            'journal_no' => generate_reference('JRN'),
            'journal_date' => $journalDate,
            'fiscal_year_id' => $period['fiscal_year_id'] ?? null,
            'period_id' => $period['id'] ?? null,
            'source_module' => $sourceModule,
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'reference_no' => $referenceNo,
            'description' => $description,
            'journal_type' => $journalType,
            'status' => 'Posted',
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => date('Y-m-d H:i:s'),
        ]);

        foreach ($lines as $line) {
            $debit = round((float) $line['debit'], 2);
            $credit = round((float) $line['credit'], 2);
            if ($debit <= 0.009 && $credit <= 0.009) {
                continue;
            }

            $this->insert('accounting_journal_lines', [
                'journal_id' => $journalId,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? $description,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        return $journalId;
    }

    /**
     * Saves a journal as Draft -- no period/fiscal_year assignment and no
     * posted_by/posted_at stamp, since it isn't in the GL yet. Balance is
     * still validated (callers should only ever submit balanced lines, but
     * this catches bugs early rather than persisting a bad draft).
     *
     * @param array<int, array{account_id:int, debit:float, credit:float, description?:string}> $lines
     */
    public function saveDraft(
        string $sourceModule,
        string $sourceTable,
        ?int $sourceId,
        string $referenceNo,
        string $description,
        array $lines,
        ?int $userId,
        string $journalDate,
        string $journalType
    ): int {
        $totalDebit = round((float) array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round((float) array_sum(array_column($lines, 'credit')), 2);
        if ($totalDebit !== $totalCredit) {
            throw new \RuntimeException("Journal not balanced. Debit: $totalDebit Credit: $totalCredit");
        }

        $journalId = $this->insert('accounting_journal_entries', [
            'journal_no' => generate_reference('JRN'),
            'journal_date' => $journalDate,
            'source_module' => $sourceModule,
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'reference_no' => $referenceNo,
            'description' => $description,
            'journal_type' => $journalType,
            'status' => 'Draft',
            'created_by' => $userId,
        ]);

        foreach ($lines as $line) {
            $this->insert('accounting_journal_lines', [
                'journal_id' => $journalId,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? $description,
                'debit' => round((float) $line['debit'], 2),
                'credit' => round((float) $line['credit'], 2),
            ]);
        }

        return $journalId;
    }

    /**
     * Replaces a Draft journal's header/lines wholesale. Throws if the
     * journal isn't a Draft -- Posted journals are corrected via reverse(),
     * never edited in place.
     *
     * @param array<int, array{account_id:int, debit:float, credit:float, description?:string}> $lines
     */
    public function updateDraft(int $journalId, string $journalDate, string $description, array $lines): void
    {
        $journal = $this->one("SELECT * FROM accounting_journal_entries WHERE id = ?", [$journalId]);
        if (!$journal) {
            throw new \RuntimeException('Journal not found.');
        }
        if ($journal['status'] !== 'Draft') {
            throw new \RuntimeException('Only Draft journals can be edited.');
        }

        $totalDebit = round((float) array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round((float) array_sum(array_column($lines, 'credit')), 2);
        if ($totalDebit !== $totalCredit) {
            throw new \RuntimeException("Journal not balanced. Debit: $totalDebit Credit: $totalCredit");
        }

        $this->update('accounting_journal_entries', [
            'journal_date' => $journalDate,
            'description' => $description,
        ], 'id', $journalId);

        $this->query("DELETE FROM accounting_journal_lines WHERE journal_id = ?", [$journalId]);
        foreach ($lines as $line) {
            $this->insert('accounting_journal_lines', [
                'journal_id' => $journalId,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? $description,
                'debit' => round((float) $line['debit'], 2),
                'credit' => round((float) $line['credit'], 2),
            ]);
        }
    }

    /**
     * Transitions a Draft journal into the GL: resolves its fiscal
     * period (blocked if that period is closed, same rule as post()),
     * stamps posted_by/posted_at, and flips status to Posted.
     */
    public function postDraft(int $journalId, int $userId): void
    {
        $journal = $this->one("SELECT * FROM accounting_journal_entries WHERE id = ?", [$journalId]);
        if (!$journal) {
            throw new \RuntimeException('Journal not found.');
        }
        if ($journal['status'] !== 'Draft') {
            throw new \RuntimeException('Only Draft journals can be posted.');
        }

        $lines = $this->all("SELECT * FROM accounting_journal_lines WHERE journal_id = ?", [$journalId]);
        if (empty($lines)) {
            throw new \RuntimeException('This journal has no lines to post.');
        }
        $totalDebit = round((float) array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round((float) array_sum(array_column($lines, 'credit')), 2);
        if ($totalDebit !== $totalCredit) {
            throw new \RuntimeException("Journal not balanced. Debit: $totalDebit Credit: $totalCredit");
        }

        $period = (new AccountingPeriod())->findByDate($journal['journal_date']);
        if ($period && (int) $period['is_closed'] === 1) {
            throw new \RuntimeException("Cannot post to {$journal['journal_date']}: accounting period \"{$period['period_name']}\" is closed.");
        }

        $this->update('accounting_journal_entries', [
            'fiscal_year_id' => $period['fiscal_year_id'] ?? null,
            'period_id' => $period['id'] ?? null,
            'status' => 'Posted',
            'posted_by' => $userId,
            'posted_at' => date('Y-m-d H:i:s'),
        ], 'id', $journalId);
    }

    /**
     * The journal_line id for a given account within a just-posted journal
     * -- used to match a newly-created adjustment straight into
     * accounting_bank_reconciliation without a second round trip.
     */
    public function lineIdForAccount(int $journalId, int $accountId): ?int
    {
        $id = $this->scalar(
            "SELECT id FROM accounting_journal_lines WHERE journal_id = ? AND account_id = ? LIMIT 1",
            [$journalId, $accountId]
        );
        return $id ? (int) $id : null;
    }

    /**
     * Reverse a posted journal: creates a new journal with debit/credit
     * flipped on every line, marks the original as Reversed. Used for
     * manual journals (automatic postings are corrected by reversing the
     * originating business action instead, not the journal directly).
     *
     * Blocked if any line's account falls within a completed (and not
     * reopened) bank reconciliation as of this journal's date -- reversing
     * it would silently invalidate a reconciliation someone already signed
     * off on. Pass $bypassLock=true for a user holding
     * accounting.reconciliation_override.
     */
    public function reverse(int $journalId, int $userId, bool $bypassLock = false): int
    {
        $journal = $this->one("SELECT * FROM accounting_journal_entries WHERE id = ?", [$journalId]);
        if (!$journal) {
            throw new \RuntimeException('Journal not found.');
        }
        if ($journal['status'] !== 'Posted') {
            throw new \RuntimeException('Only posted journals can be reversed.');
        }

        $lines = $this->all("SELECT * FROM accounting_journal_lines WHERE journal_id = ?", [$journalId]);
        if (empty($lines)) {
            throw new \RuntimeException('No lines found to reverse.');
        }

        if (!$bypassLock) {
            $reconciliation = new BankReconciliation();
            foreach ($lines as $l) {
                if ($reconciliation->isLockedForAccount((int) $l['account_id'], $journal['journal_date'])) {
                    throw new \RuntimeException('This transaction falls within a completed bank reconciliation and cannot be reversed. Reopen that reconciliation first, or ask an admin to override.');
                }
            }
        }

        $reversalLines = array_map(static fn ($l) => [
            'account_id' => (int) $l['account_id'],
            'debit' => (float) $l['credit'],
            'credit' => (float) $l['debit'],
            'description' => 'Reversal: ' . $l['description'],
        ], $lines);

        $reversalId = $this->post(
            'REVERSAL',
            'accounting_journal_entries',
            $journalId,
            $journal['reference_no'],
            'Reversal of ' . $journal['journal_no'] . ' - ' . $journal['description'],
            $reversalLines,
            $userId,
            date('Y-m-d'),
            'Reversal'
        );

        $this->update('accounting_journal_entries', ['status' => 'Reversed'], 'id', $journalId);
        $this->update('accounting_journal_entries', ['reversed_from' => $journalId], 'id', $reversalId);

        return $reversalId;
    }
}
