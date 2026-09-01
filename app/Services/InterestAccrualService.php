<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\InterestAccrual;

/**
 * Recognizes loan interest income as it is earned, rather than only when a
 * client pays -- the accrual-basis counterpart to PenaltyAccrualService,
 * built to the same shape. A loan_schedules row's interest becomes earned
 * the moment its due date arrives (or immediately, if a payment collects
 * it ahead of that date -- see ensureAccrued()); once earned, it is posted
 * straight to Interest Income (4010), never through a deferred account.
 * interest_accruals is both the audit trail and the idempotency guard (one
 * row per schedule_id, enforced at the DB level too via a unique key) --
 * a schedule row can only ever be accrued once.
 *
 * accrue() is called from two places: automatically from
 * Payment::allocateToSchedule()/allocateToSpecificSchedule() right before
 * a payment is applied (scoped to that one loan, as of the payment date --
 * recognizes any due installment this payment doesn't happen to reach),
 * and from the manual "Interest Accruals" screen
 * (InterestAccrualController::post(), portfolio-wide) plus a daily cron
 * (bin/accrue_interest.php) so every period's earned interest is recognized
 * before that period's books close, independent of any payment activity.
 *
 * ensureAccrued() is the early-payment path: when a payment collects a
 * schedule row's interest before its due date has naturally arrived, that
 * row must still be recognized as income right now (never only at
 * collection) -- it books the row's FULL interest_due immediately,
 * regardless of how much of it this particular payment actually collects,
 * so a later partial payment or the row's real due date arriving can never
 * re-trigger or double-book it.
 *
 * recognizeUpfront() is the disbursement-time path for a loan whose
 * interest_recognition_method is 'Upfront' -- a flat, non-refundable fee
 * fully earned the moment the loan is disbursed, not progressively. It
 * recognizes the loan's ENTIRE interest in one journal, dated the
 * disbursement date, and pre-fills interest_accruals for every schedule row
 * so accrue()'s daily cron and the manual screen never touch that loan
 * again. See LoanController's disbursement flow for where it's called.
 */
class InterestAccrualService
{
    /**
     * Every loan_schedules row (optionally scoped to one loan) whose due
     * date has arrived as of $asOfDate, still carries interest, and hasn't
     * already been accrued.
     */
    public static function accruableInstallments(string $asOfDate, ?int $loanId = null): array
    {
        $db = Database::connection();
        $sql = "SELECT ls.id AS schedule_id, ls.loan_id, ls.due_date, ls.interest_due,
                       l.borrower_id, l.loan_no, CONCAT(b.first_name,' ',b.last_name) AS borrower_name
                FROM loan_schedules ls
                JOIN loans l ON l.id = ls.loan_id
                JOIN borrowers b ON b.id = l.borrower_id
                WHERE l.loan_status IN ('Active', 'Current', 'Released')
                  AND ls.interest_due > 0
                  AND ls.due_date <= ?
                  AND NOT EXISTS (
                      SELECT 1 FROM interest_accruals ia WHERE ia.schedule_id = ls.id
                  )";
        $params = [$asOfDate];

        if ($loanId !== null) {
            $sql .= " AND l.id = ?";
            $params[] = $loanId;
        }

        $sql .= " ORDER BY ls.due_date";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['interest_amount'] = round((float) $row['interest_due'], 2);
        }
        unset($row);

        return array_values(array_filter($rows, fn ($r) => $r['interest_amount'] > 0));
    }

    /**
     * Accrues every accruable installment as of $asOfDate (optionally
     * scoped to one loan): groups them by due_date and posts one journal
     * per distinct date (Dr Interest Receivable / Cr Interest Income),
     * dated that installment's own due date -- never "today" -- so a
     * multi-period catch-up run (e.g. after a missed cron day) can never
     * misdate an earlier period's earned interest into the current one.
     * Inserts one interest_accruals row per installment. Returns the
     * installments accrued -- empty if there was nothing to accrue.
     */
    public static function accrue(string $asOfDate, ?int $userId, ?int $loanId = null): array
    {
        $installments = self::accruableInstallments($asOfDate, $loanId);
        if (empty($installments)) {
            return [];
        }

        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();
        $accruals = new InterestAccrual();

        $byDueDate = [];
        foreach ($installments as $row) {
            $byDueDate[$row['due_date']][] = $row;
        }

        foreach ($byDueDate as $dueDate => $rows) {
            $total = round(array_sum(array_column($rows, 'interest_amount')), 2);
            if ($total <= 0) {
                continue;
            }

            $journal->post(
                'INTEREST_ACCRUAL',
                'loan_schedules',
                null,
                generate_reference('IAJ'),
                'Interest accrued for installment(s) due ' . $dueDate,
                [
                    ['account_id' => $accounts->idByCode('1030'), 'debit' => $total, 'credit' => 0],
                    ['account_id' => $accounts->idByCode('4010'), 'debit' => 0, 'credit' => $total],
                ],
                $userId,
                $dueDate,
                'Automatic'
            );

            foreach ($rows as $row) {
                $accruals->create([
                    'loan_id' => $row['loan_id'],
                    'borrower_id' => $row['borrower_id'],
                    'schedule_id' => $row['schedule_id'],
                    'accrual_no' => generate_reference('IAC'),
                    'accrual_date' => $dueDate,
                    'amount' => $row['interest_amount'],
                    'status' => 'Accrued',
                    'accrued_by' => $userId,
                ]);
            }
        }

        return $installments;
    }

    /**
     * Recognizes a loan's ENTIRE remaining interest in one shot, dated
     * $asOfDate (the disbursement date) -- for a loan whose interest is a
     * flat, non-refundable fee fully earned the moment it's disbursed
     * (loans.interest_recognition_method = 'Upfront'), rather than earned
     * progressively as each installment's due date arrives. Called once,
     * from LoanController's disbursement flow.
     *
     * Unlike accrue(), this is NOT gated on due_date <= $asOfDate -- it
     * takes every one of the loan's schedule rows regardless of how far in
     * the future their due dates are. Posts ONE journal (Dr 1030 / Cr 4010)
     * for the loan's full interest total, then inserts one interest_accruals
     * row per schedule row (each dated $asOfDate, not its own due date) --
     * this is what makes accruableInstallments()'s NOT EXISTS filter skip
     * every row of this loan permanently, in both the daily cron and the
     * manual accrual screen, with no further change needed anywhere else.
     * No-ops (returns []) if the loan has no schedule rows, no interest, or
     * has already been recognized (idempotent, same as accrue()).
     */
    public static function recognizeUpfront(int $loanId, string $asOfDate, ?int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT ls.id AS schedule_id, ls.loan_id, ls.interest_due, l.borrower_id, l.loan_no
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             WHERE ls.loan_id = ?
               AND NOT EXISTS (SELECT 1 FROM interest_accruals ia WHERE ia.schedule_id = ls.id)
             ORDER BY ls.installment_no"
        );
        $stmt->execute([$loanId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['interest_amount'] = round((float) $row['interest_due'], 2);
        }
        unset($row);
        $rows = array_values(array_filter($rows, fn ($r) => $r['interest_amount'] > 0));

        if (empty($rows)) {
            return [];
        }

        $total = round(array_sum(array_column($rows, 'interest_amount')), 2);
        if ($total <= 0) {
            return [];
        }

        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();
        $accruals = new InterestAccrual();

        $journal->post(
            'INTEREST_ACCRUAL',
            'loans',
            $loanId,
            generate_reference('IAJ'),
            'Full interest recognized upfront at disbursement for ' . $rows[0]['loan_no'],
            [
                ['account_id' => $accounts->idByCode('1030'), 'debit' => $total, 'credit' => 0],
                ['account_id' => $accounts->idByCode('4010'), 'debit' => 0, 'credit' => $total],
            ],
            $userId,
            $asOfDate,
            'Automatic'
        );

        foreach ($rows as $row) {
            $accruals->create([
                'loan_id' => $row['loan_id'],
                'borrower_id' => $row['borrower_id'],
                'schedule_id' => $row['schedule_id'],
                'accrual_no' => generate_reference('IAC'),
                'accrual_date' => $asOfDate,
                'amount' => $row['interest_amount'],
                'status' => 'Accrued',
                'accrued_by' => $userId,
            ]);
        }

        return $rows;
    }

    /**
     * Recognizes one specific schedule row's interest right now, regardless
     * of its due date -- used when a payment is about to collect interest
     * that hasn't naturally accrued yet (an early/advance payment). No-op
     * if the row is already accrued, has no interest, or doesn't exist.
     * Books the row's FULL interest_due, not just what's being collected --
     * see class docblock for why.
     */
    public static function ensureAccrued(int $scheduleId, string $asOfDate, ?int $userId): ?array
    {
        $accruals = new InterestAccrual();
        if ($accruals->findByScheduleId($scheduleId)) {
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT ls.id AS schedule_id, ls.loan_id, ls.due_date, ls.interest_due, l.borrower_id, l.loan_no
             FROM loan_schedules ls
             JOIN loans l ON l.id = ls.loan_id
             WHERE ls.id = ?"
        );
        $stmt->execute([$scheduleId]);
        $row = $stmt->fetch();

        if (!$row || (float) $row['interest_due'] <= 0) {
            return null;
        }

        $amount = round((float) $row['interest_due'], 2);
        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();

        $journal->post(
            'INTEREST_ACCRUAL',
            'loan_schedules',
            $scheduleId,
            generate_reference('IAJ'),
            'Interest recognized ahead of due date ' . $row['due_date'] . ' for ' . $row['loan_no'] . ' (advance payment)',
            [
                ['account_id' => $accounts->idByCode('1030'), 'debit' => $amount, 'credit' => 0],
                ['account_id' => $accounts->idByCode('4010'), 'debit' => 0, 'credit' => $amount],
            ],
            $userId,
            $asOfDate,
            'Automatic'
        );

        $accruals->create([
            'loan_id' => $row['loan_id'],
            'borrower_id' => $row['borrower_id'],
            'schedule_id' => $scheduleId,
            'accrual_no' => generate_reference('IAC'),
            'accrual_date' => $asOfDate,
            'amount' => $amount,
            'status' => 'Accrued',
            'accrued_by' => $userId,
        ]);

        return $row;
    }
}
