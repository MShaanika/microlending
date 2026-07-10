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
}
