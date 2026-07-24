<?php

namespace App\Models;

use App\Core\Model;

class BankReconciliation extends Model
{
    /**
     * Posted journal lines against a GL account that haven't been matched
     * to a bank statement line yet -- the pool of candidates for both
     * auto-matching and the manual matching screen.
     */
    public function unmatchedJournalLines(int $glAccountId, int $limit = 300): array
    {
        return $this->all(
            "SELECT jl.id AS journal_line_id, jl.debit, jl.credit, jl.description,
                    je.id AS journal_id, je.journal_no, je.journal_date, je.description AS journal_description
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE jl.account_id = ? AND je.status = 'Posted'
               AND jl.id NOT IN (
                   SELECT journal_line_id FROM accounting_bank_reconciliation
                   WHERE reconciliation_status IN ('Matched', 'Manual')
               )
             ORDER BY je.journal_date, jl.id
             LIMIT " . (int) $limit,
            [$glAccountId]
        );
    }

    public function match(int $bankStatementId, int $journalLineId, float $amount, string $status, ?int $userId, ?string $notes = null): int
    {
        $id = $this->insert('accounting_bank_reconciliation', [
            'bank_statement_id' => $bankStatementId,
            'journal_line_id' => $journalLineId,
            'matched_amount' => $amount,
            'reconciliation_status' => $status,
            'reconciled_by' => $userId,
            'reconciled_at' => date('Y-m-d H:i:s'),
            'notes' => $notes,
        ]);

        (new BankStatementLine())->markReconciled($bankStatementId);

        return $id;
    }

    public function findByStatementId(int $bankStatementId): ?array
    {
        return $this->one(
            "SELECT br.*, jl.debit, jl.credit, je.journal_no, je.journal_date, je.description AS journal_description
             FROM accounting_bank_reconciliation br
             JOIN accounting_journal_lines jl ON jl.id = br.journal_line_id
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE br.bank_statement_id = ?",
            [$bankStatementId]
        );
    }

    public function unmatch(int $bankStatementId): bool
    {
        $this->query("DELETE FROM accounting_bank_reconciliation WHERE bank_statement_id = ?", [$bankStatementId]);
        return (new BankStatementLine())->markUnreconciled($bankStatementId);
    }

    /**
     * Straightforward exact-match auto-matching: for each unreconciled
     * statement line, look for exactly one unreconciled journal line on
     * the same GL account with the same amount (money_in <-> debit,
     * money_out <-> credit) within a short date window to allow for bank
     * clearing delays. Ambiguous or missing matches are left for manual
     * review rather than guessed at.
     */
    public function autoMatch(int $bankAccountId, int $glAccountId, ?int $userId, int $dateToleranceDays = 5): int
    {
        $statementLines = (new BankStatementLine())->unreconciled($bankAccountId);
        $journalLines = $this->unmatchedJournalLines($glAccountId);

        $matchedJournalLineIds = [];
        $matchedCount = 0;

        foreach ($statementLines as $line) {
            $moneyIn = round((float) $line['money_in'], 2);
            $moneyOut = round((float) $line['money_out'], 2);
            $lineDate = strtotime($line['transaction_date']);

            $candidates = [];
            foreach ($journalLines as $jl) {
                if (in_array($jl['journal_line_id'], $matchedJournalLineIds, true)) {
                    continue;
                }
                $debit = round((float) $jl['debit'], 2);
                $credit = round((float) $jl['credit'], 2);

                $amountMatches = ($moneyIn > 0 && abs($debit - $moneyIn) < 0.01)
                    || ($moneyOut > 0 && abs($credit - $moneyOut) < 0.01);
                if (!$amountMatches) {
                    continue;
                }

                $daysApart = abs($lineDate - strtotime($jl['journal_date'])) / 86400;
                if ($daysApart > $dateToleranceDays) {
                    continue;
                }

                $candidates[] = $jl;
            }

            if (count($candidates) === 1) {
                $jl = $candidates[0];
                $amount = $moneyIn > 0 ? $moneyIn : $moneyOut;
                $this->match((int) $line['id'], (int) $jl['journal_line_id'], $amount, 'Matched', $userId, 'Auto-matched');
                $matchedJournalLineIds[] = $jl['journal_line_id'];
                $matchedCount++;
            }
        }

        return $matchedCount;
    }

    /**
     * The classic bank reconciliation walk: GL balance as of the statement
     * date, adjusted for items on one side but not the other yet, should
     * equal the statement's own closing balance.
     */
    public function summary(int $bankAccountId, int $glAccountId, string $asOfDate): array
    {
        $db = \App\Core\Database::connection();

        $glBalanceRow = $db->prepare(
            "SELECT COALESCE(SUM(jl.debit),0) AS total_debit, COALESCE(SUM(jl.credit),0) AS total_credit
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE jl.account_id = ? AND je.status = 'Posted' AND je.journal_date <= ?"
        );
        $glBalanceRow->execute([$glAccountId, $asOfDate]);
        $gl = $glBalanceRow->fetch();
        $glBalance = round((float) $gl['total_debit'] - (float) $gl['total_credit'], 2);

        // In the books but not yet on the bank statement (outstanding).
        $outstandingRow = $db->prepare(
            "SELECT jl.debit, jl.credit
             FROM accounting_journal_lines jl
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE jl.account_id = ? AND je.status = 'Posted' AND je.journal_date <= ?
               AND jl.id NOT IN (
                   SELECT journal_line_id FROM accounting_bank_reconciliation
                   WHERE reconciliation_status IN ('Matched', 'Manual')
               )"
        );
        $outstandingRow->execute([$glAccountId, $asOfDate]);
        $outstandingDeposits = 0.0;
        $outstandingPayments = 0.0;
        foreach ($outstandingRow->fetchAll() as $row) {
            $outstandingDeposits += (float) $row['debit'];
            $outstandingPayments += (float) $row['credit'];
        }

        // On the bank statement but not yet reconciled in the books.
        $unreconciledStatement = (new BankStatementLine())->unreconciled($bankAccountId);
        $unrecordedDeposits = 0.0;
        $unrecordedPayments = 0.0;
        foreach ($unreconciledStatement as $row) {
            if (strtotime($row['transaction_date']) > strtotime($asOfDate)) {
                continue;
            }
            $unrecordedDeposits += (float) $row['money_in'];
            $unrecordedPayments += (float) $row['money_out'];
        }

        $statementBalance = (new BankStatementLine())->latestBalance($bankAccountId);

        // Adjusted book balance: what the GL would show once the bank-only
        // items still sitting unreconciled on the statement (fees, interest,
        // debit orders the app doesn't know about yet) are posted.
        $adjustedBookBalance = round($glBalance + $unrecordedDeposits - $unrecordedPayments, 2);

        // Adjusted bank balance: what the statement would show once items
        // already in the books but not yet cleared by the bank (deposits/
        // payments in transit) catch up.
        $adjustedBankBalance = $statementBalance === null
            ? null
            : round($statementBalance + $outstandingDeposits - $outstandingPayments, 2);

        return [
            'gl_balance' => $glBalance,
            'outstanding_deposits' => round($outstandingDeposits, 2),
            'outstanding_payments' => round($outstandingPayments, 2),
            'unrecorded_deposits' => round($unrecordedDeposits, 2),
            'unrecorded_payments' => round($unrecordedPayments, 2),
            'adjusted_book_balance' => $adjustedBookBalance,
            'statement_balance' => $statementBalance,
            'adjusted_bank_balance' => $adjustedBankBalance,
            'difference' => $adjustedBankBalance === null ? null : round($adjustedBankBalance - $adjustedBookBalance, 2),
        ];
    }

    /**
     * One row per side-by-side comparison item, date order: Matched pairs
     * (✅, or Review if the two sides' amounts don't quite agree -- a bank
     * fee shaved off a deposit, say), statement-only lines with no journal
     * match (Missing -- forgot to record it), and journal-only lines with
     * no statement match yet (Unmatched -- an outstanding/uncashed item).
     * Composed from the same underlying queries the rest of this model
     * already uses, rather than one large UNION, so match/auto-match logic
     * stays in one place.
     */
    public function comparisonTable(int $bankAccountId, int $glAccountId, string $asOfDate, ?string $sinceDate = null): array
    {
        $db = \App\Core\Database::connection();

        $matchedStmt = $db->prepare(
            "SELECT br.id AS reconciliation_id, br.bank_statement_id, br.journal_line_id,
                    bs.transaction_date, bs.description AS statement_description, bs.money_in, bs.money_out,
                    jl.debit, jl.credit, jl.description AS journal_description,
                    je.journal_no, je.id AS journal_id
             FROM accounting_bank_reconciliation br
             JOIN accounting_bank_statement bs ON bs.id = br.bank_statement_id
             JOIN accounting_journal_lines jl ON jl.id = br.journal_line_id
             JOIN accounting_journal_entries je ON je.id = jl.journal_id
             WHERE bs.bank_account_id = ?
             ORDER BY bs.transaction_date"
        );
        $matchedStmt->execute([$bankAccountId]);

        $rows = [];
        foreach ($matchedStmt->fetchAll() as $m) {
            $cashBookAmount = (float) $m['debit'] > 0 ? (float) $m['debit'] : -((float) $m['credit']);
            $bankAmount = (float) $m['money_in'] > 0 ? (float) $m['money_in'] : -((float) $m['money_out']);
            $rows[] = [
                'status' => abs($cashBookAmount - $bankAmount) > 0.01 ? 'Review' : 'Matched',
                'date' => $m['transaction_date'],
                'description' => $m['journal_description'] ?: $m['statement_description'],
                'cash_book_amount' => (float) $m['debit'] > 0 ? (float) $m['debit'] : ((float) $m['credit'] > 0 ? -(float) $m['credit'] : null),
                'bank_amount' => (float) $m['money_in'] > 0 ? (float) $m['money_in'] : ((float) $m['money_out'] > 0 ? -(float) $m['money_out'] : null),
                'journal_id' => (int) $m['journal_id'],
                'journal_no' => $m['journal_no'],
                'bank_statement_id' => (int) $m['bank_statement_id'],
            ];
        }

        foreach ((new BankStatementLine())->unreconciled($bankAccountId) as $s) {
            $rows[] = [
                'status' => 'Missing',
                'date' => $s['transaction_date'],
                'description' => $s['description'] ?: $s['reference_no'],
                'cash_book_amount' => null,
                'bank_amount' => (float) $s['money_in'] > 0 ? (float) $s['money_in'] : -((float) $s['money_out']),
                'journal_id' => null,
                'journal_no' => null,
                'bank_statement_id' => (int) $s['id'],
            ];
        }

        foreach ($this->unmatchedJournalLines($glAccountId) as $jl) {
            $rows[] = [
                'status' => 'Unmatched',
                'date' => $jl['journal_date'],
                'description' => $jl['description'] ?: $jl['journal_description'],
                'cash_book_amount' => (float) $jl['debit'] > 0 ? (float) $jl['debit'] : -((float) $jl['credit']),
                'bank_amount' => null,
                'journal_id' => (int) $jl['journal_id'],
                'journal_no' => $jl['journal_no'],
                'bank_statement_id' => null,
            ];
        }

        $rows = array_values(array_filter($rows, static function ($r) use ($asOfDate, $sinceDate) {
            if ($r['date'] > $asOfDate) {
                return false;
            }
            if ($sinceDate !== null && $r['date'] <= $sinceDate) {
                return false;
            }
            return true;
        }));

        usort($rows, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * "Complete Reconciliation": records the cutoff. Every posted journal
     * line against $glAccountId dated on or before $statementDate is
     * locked (see isLocked()) from that point on, unless this row is later
     * reopened. Only call once the caller has confirmed difference = 0 --
     * this method doesn't re-check it, so it can also be used to reopen/
     * redo a period via reopen() + complete() again.
     */
    public function complete(int $bankAccountId, string $statementDate, int $userId): int
    {
        return $this->insert('bank_reconciliation_completions', [
            'bank_account_id' => $bankAccountId,
            'statement_date' => $statementDate,
            'completed_by' => $userId,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Reopens the active (not already reopened) completion for a bank
     * account, if any -- lifting the lock. Returns false if there was
     * nothing active to reopen.
     */
    public function reopen(int $bankAccountId, int $userId): bool
    {
        $id = $this->scalar(
            "SELECT id FROM bank_reconciliation_completions
             WHERE bank_account_id = ? AND reopened_at IS NULL
             ORDER BY statement_date DESC LIMIT 1",
            [$bankAccountId]
        );
        if (!$id) {
            return false;
        }

        return $this->update('bank_reconciliation_completions', [
            'reopened_by' => $userId,
            'reopened_at' => date('Y-m-d H:i:s'),
        ], 'id', (int) $id);
    }

    /**
     * The latest active (not reopened) completion cutoff for a bank
     * account, or null if the account has never been completed / its last
     * completion was reopened.
     */
    public function lockedThrough(int $bankAccountId): ?array
    {
        return $this->one(
            "SELECT bc.*, u.name AS completed_by_name
             FROM bank_reconciliation_completions bc
             LEFT JOIN users u ON u.id = bc.completed_by
             WHERE bc.bank_account_id = ? AND bc.reopened_at IS NULL
             ORDER BY bc.statement_date DESC LIMIT 1",
            [$bankAccountId]
        );
    }

    public function completionHistory(int $bankAccountId): array
    {
        return $this->all(
            "SELECT bc.*, u.name AS completed_by_name, ru.name AS reopened_by_name
             FROM bank_reconciliation_completions bc
             LEFT JOIN users u ON u.id = bc.completed_by
             LEFT JOIN users ru ON ru.id = bc.reopened_by
             WHERE bc.bank_account_id = ?
             ORDER BY bc.id DESC",
            [$bankAccountId]
        );
    }

    /**
     * Whether a journal dated $journalDate against $glAccountId falls
     * within a completed (and not reopened) reconciliation for the bank
     * account attached to that GL account -- i.e. reversing it would
     * silently invalidate a reconciliation someone already signed off on.
     */
    public function isLockedForAccount(int $glAccountId, string $journalDate): bool
    {
        $cutoff = $this->scalar(
            "SELECT MAX(bc.statement_date)
             FROM bank_reconciliation_completions bc
             JOIN accounting_bank_accounts ba ON ba.id = bc.bank_account_id
             WHERE ba.account_id = ? AND bc.reopened_at IS NULL",
            [$glAccountId]
        );

        return $cutoff !== null && $journalDate <= $cutoff;
    }
}
