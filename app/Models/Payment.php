<?php

namespace App\Models;

use App\Core\Model;

class Payment extends Model
{
    public function paginated(string $search = '', int $limit = 100, ?int $branchId = null): array
    {
        $sql = "SELECT p.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, l.loan_no, u.name AS posted_by_name
                FROM payments p
                JOIN borrowers b ON b.id = p.borrower_id
                JOIN loans l ON l.id = p.loan_id
                LEFT JOIN users u ON u.id = p.posted_by
                WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $sql .= " AND (p.payment_no LIKE ? OR l.loan_no LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        if ($branchId !== null) {
            $sql .= " AND p.branch_id = ?";
            $params[] = $branchId;
        }

        $sql .= " ORDER BY p.id DESC LIMIT " . (int) $limit;

        return $this->all($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT p.*, CONCAT(b.first_name,' ',b.last_name) AS borrower_name, l.loan_no
             FROM payments p
             JOIN borrowers b ON b.id = p.borrower_id
             JOIN loans l ON l.id = p.loan_id
             WHERE p.id = ?",
            [$id]
        );
    }

    public function forBorrower(int $borrowerId): array
    {
        return $this->all(
            "SELECT p.*, l.loan_no FROM payments p JOIN loans l ON l.id = p.loan_id
             WHERE p.borrower_id = ? ORDER BY p.id DESC",
            [$borrowerId]
        );
    }

    public function totalCollected(?int $branchId = null): float
    {
        if ($branchId !== null) {
            return (float) ($this->scalar("SELECT COALESCE(SUM(amount_received),0) FROM payments WHERE status = 'Posted' AND branch_id = ?", [$branchId]) ?: 0);
        }
        return (float) ($this->scalar("SELECT COALESCE(SUM(amount_received),0) FROM payments WHERE status = 'Posted'") ?: 0);
    }

    public function pendingCount(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM payments WHERE status = 'Pending'");
    }

    /**
     * Record a payment against a loan and allocate it across the outstanding
     * amortization schedule using an interest -> fees -> NAMFISA levy ->
     * duty stamp -> principal -> penalty waterfall, then post the matching
     * accounting journal. Used by staff for a payment collected and
     * confirmed on the spot.
     */
    public function recordAndAllocate(array $loan, float $amount, array $meta): int
    {
        $this->db->beginTransaction();

        try {
            $paymentId = $this->insert('payments', [
                'loan_id' => $loan['id'],
                'borrower_id' => $loan['borrower_id'],
                'branch_id' => $loan['branch_id'],
                'payment_no' => generate_reference('PMT'),
                'payment_date' => $meta['payment_date'],
                'payment_source' => $meta['payment_source'],
                'bank_account_id' => $meta['bank_account_id'] ?? null,
                'amount_received' => $amount,
                'principal_amount' => 0,
                'interest_amount' => 0,
                'fees_amount' => 0,
                'namfisa_levy_amount' => 0,
                'duty_stamp_amount' => 0,
                'penalty_amount' => 0,
                'overpayment_amount' => 0,
                'reference_no' => $meta['reference_no'] ?: null,
                'payer_name' => $meta['payer_name'] ?: null,
                'notes' => $meta['notes'] ?: null,
                'status' => 'Posted',
                'collected_by' => $meta['user_id'],
                'posted_by' => $meta['user_id'],
                'posted_at' => date('Y-m-d H:i:s'),
            ]);

            $this->allocateToSchedule($loan, $paymentId, $amount, $meta['user_id'], $meta['bank_account_id'] ?? null, $meta['payment_date']);

            $this->db->commit();
            \App\Core\Events::fire('PaymentReceived', ['payment_id' => $paymentId, 'loan_id' => $loan['id'], 'amount' => $amount]);
            return $paymentId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Targeted counterpart to recordAndAllocate(): applies the payment to
     * exactly one loan_schedules row instead of FIFO-walking every unpaid
     * row. Used for a split debit order leg's collection result, where two
     * independent collection events must always combine onto the SAME
     * schedule row for the "both succeed = Paid, one succeeds = Partial"
     * rule to hold regardless of arrears elsewhere on the loan -- FIFO
     * alone can't guarantee that (see Payment::allocateToSpecificSchedule()).
     * A no-op (payment still recorded, nothing allocated) if the row is
     * already Paid or doesn't belong to this loan.
     */
    public function recordAndAllocateToScheduleId(array $loan, int $scheduleId, float $amount, array $meta): int
    {
        $this->db->beginTransaction();

        try {
            $paymentId = $this->insert('payments', [
                'loan_id' => $loan['id'],
                'borrower_id' => $loan['borrower_id'],
                'branch_id' => $loan['branch_id'],
                'payment_no' => generate_reference('PMT'),
                'payment_date' => $meta['payment_date'],
                'payment_source' => $meta['payment_source'],
                'bank_account_id' => $meta['bank_account_id'] ?? null,
                'amount_received' => $amount,
                'principal_amount' => 0,
                'interest_amount' => 0,
                'fees_amount' => 0,
                'namfisa_levy_amount' => 0,
                'duty_stamp_amount' => 0,
                'penalty_amount' => 0,
                'overpayment_amount' => 0,
                'reference_no' => $meta['reference_no'] ?: null,
                'payer_name' => $meta['payer_name'] ?: null,
                'notes' => $meta['notes'] ?: null,
                'status' => 'Posted',
                'collected_by' => $meta['user_id'],
                'posted_by' => $meta['user_id'],
                'posted_at' => date('Y-m-d H:i:s'),
            ]);

            $this->allocateToSpecificSchedule($loan, $scheduleId, $paymentId, $amount, $meta['user_id'], $meta['bank_account_id'] ?? null, $meta['payment_date']);

            $this->db->commit();
            return $paymentId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Log a payment reference reported by a borrower through the portal.
     * Nothing is allocated to the schedule yet — it just sits as 'Pending'
     * until a staff member reviews and confirms it via confirmPending().
     */
    public function logPendingReference(array $loan, float $amount, array $meta): int
    {
        return $this->insert('payments', [
            'loan_id' => $loan['id'],
            'borrower_id' => $loan['borrower_id'],
            'branch_id' => $loan['branch_id'],
            'payment_no' => generate_reference('PMT'),
            'payment_date' => $meta['payment_date'],
            'payment_source' => $meta['payment_source'],
            'amount_received' => $amount,
            'principal_amount' => 0,
            'interest_amount' => 0,
            'fees_amount' => 0,
            'namfisa_levy_amount' => 0,
            'duty_stamp_amount' => 0,
            'penalty_amount' => 0,
            'overpayment_amount' => 0,
            'reference_no' => $meta['reference_no'] ?: null,
            'payer_name' => $meta['payer_name'] ?: null,
            'notes' => $meta['notes'] ?: null,
            'status' => 'Pending',
            'collected_by' => null,
            'posted_by' => null,
            'posted_at' => null,
        ]);
    }

    /**
     * Staff confirms a borrower-reported payment reference: allocates it to
     * the schedule (same waterfall as a directly-recorded payment), posts
     * the accounting journal, and marks it Posted.
     */
    public function confirmPending(int $paymentId, int $staffUserId, ?int $bankAccountId = null): bool
    {
        $payment = $this->one("SELECT * FROM payments WHERE id = ? AND status = 'Pending'", [$paymentId]);
        if (!$payment) {
            return false;
        }

        $loan = $this->one("SELECT * FROM loans WHERE id = ?", [$payment['loan_id']]);
        if (!$loan) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            $this->allocateToSchedule($loan, $paymentId, (float) $payment['amount_received'], $staffUserId, $bankAccountId, $payment['payment_date']);

            $this->update('payments', [
                'status' => 'Posted',
                'posted_by' => $staffUserId,
                'posted_at' => date('Y-m-d H:i:s'),
                'collected_by' => $staffUserId,
                'bank_account_id' => $bankAccountId,
            ], 'id', $paymentId);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function rejectPending(int $paymentId, int $staffUserId, string $reason): bool
    {
        return $this->update('payments', [
            'status' => 'Cancelled',
            'notes' => trim(($this->scalar('SELECT notes FROM payments WHERE id = ?', [$paymentId]) ?: '') . ' | Rejected: ' . $reason),
            'posted_by' => $staffUserId,
            'posted_at' => date('Y-m-d H:i:s'),
        ], 'id', $paymentId);
    }

    /**
     * Shared interest -> fees -> NAMFISA levy -> duty stamp -> principal ->
     * penalty waterfall allocation, followed by the matching accounting
     * journal. Assumes it runs inside an already-open transaction.
     */
    private function allocateToSchedule(array $loan, int $paymentId, float $amount, ?int $userId, ?int $bankAccountId = null, ?string $paymentDate = null): void
    {
        $asOfDate = $paymentDate ?? date('Y-m-d');

        // Auto-accrue any installment that's gone past its grace period as
        // of this payment's date, scoped to this loan, before allocating --
        // this is what makes penalty charging automatic instead of relying
        // on staff to run the manual Penalty Accruals screen first. A no-op
        // if nothing is newly chargeable (already-charged installments are
        // skipped by the NOT EXISTS guard in PenaltyAccrualService).
        \App\Services\PenaltyAccrualService::accrue($asOfDate, $userId, (int) $loan['id']);

        // Same idea for interest: recognizes income for any due installment
        // this payment doesn't happen to reach. The installment(s) this
        // payment DOES reach are covered per-row in allocateRowAndAccumulate()
        // via ensureAccrued(), which also handles early/advance payments
        // that arrive before an installment's due date.
        \App\Services\InterestAccrualService::accrue($asOfDate, $userId, (int) $loan['id']);

        $remaining = $amount;
        $totals = ['principal' => 0.0, 'interest' => 0.0, 'fees' => 0.0, 'namfisa_levy' => 0.0, 'duty_stamp' => 0.0, 'penalty' => 0.0];

        $rows = $this->all(
            "SELECT * FROM loan_schedules WHERE loan_id = ? AND status != 'Paid' ORDER BY installment_no",
            [$loan['id']]
        );

        foreach ($rows as $row) {
            if ($remaining <= 0.009) {
                break;
            }
            $remaining = $this->allocateRowAndAccumulate($row, $paymentId, (int) $loan['id'], $remaining, $totals, $asOfDate, $userId);
        }

        $this->finalizeAllocation($loan, $paymentId, $amount, $totals, $remaining, $userId, $bankAccountId, $paymentDate);
    }

    /**
     * Targeted counterpart to allocateToSchedule() -- applies the waterfall
     * to exactly one loan_schedules row (looked up here, scoped to this
     * loan) instead of FIFO-walking every unpaid row. See
     * recordAndAllocateToScheduleId()'s docblock for why this exists.
     */
    private function allocateToSpecificSchedule(array $loan, int $scheduleId, int $paymentId, float $amount, ?int $userId, ?int $bankAccountId = null, ?string $paymentDate = null): void
    {
        $asOfDate = $paymentDate ?? date('Y-m-d');

        \App\Services\PenaltyAccrualService::accrue($asOfDate, $userId, (int) $loan['id']);
        \App\Services\InterestAccrualService::accrue($asOfDate, $userId, (int) $loan['id']);

        $remaining = $amount;
        $totals = ['principal' => 0.0, 'interest' => 0.0, 'fees' => 0.0, 'namfisa_levy' => 0.0, 'duty_stamp' => 0.0, 'penalty' => 0.0];

        $row = $this->one("SELECT * FROM loan_schedules WHERE id = ? AND loan_id = ?", [$scheduleId, $loan['id']]);
        if ($row && $row['status'] !== 'Paid') {
            $remaining = $this->allocateRowAndAccumulate($row, $paymentId, (int) $loan['id'], $remaining, $totals, $asOfDate, $userId);
        }

        $this->finalizeAllocation($loan, $paymentId, $amount, $totals, $remaining, $userId, $bankAccountId, $paymentDate);
    }

    /**
     * Applies the interest -> fees -> NAMFISA levy -> duty stamp ->
     * principal -> penalty waterfall to one loan_schedules row, persists
     * its updated *_paid/status columns and matching payment_allocations
     * line, accumulates onto $totals by reference, and returns whatever of
     * $remaining is left unallocated. Shared by the FIFO (allocateToSchedule)
     * and targeted (allocateToSpecificSchedule) allocation paths so both
     * apply identical per-row logic.
     *
     * Before touching this row's interest, ensures it's been recognized as
     * income (InterestAccrualService::ensureAccrued()) -- covers the case
     * where this payment collects a row's interest before its due date has
     * naturally accrued it (an early/advance payment), so income is always
     * recognized no later than the moment it's collected, never only then.
     */
    private function allocateRowAndAccumulate(array $row, int $paymentId, int $loanId, float $remaining, array &$totals, ?string $asOfDate = null, ?int $userId = null): float
    {
        if ((float) $row['interest_due'] > 0) {
            \App\Services\InterestAccrualService::ensureAccrued((int) $row['id'], $asOfDate ?? date('Y-m-d'), $userId);
        }

        $outstanding = [
            'penalty' => round((float) $row['penalty_due'] - (float) $row['penalty_paid'], 2),
            'interest' => round((float) $row['interest_due'] - (float) $row['interest_paid'], 2),
            'fees' => round((float) $row['fees_due'] - (float) $row['fees_paid'], 2),
            'namfisa_levy' => round((float) $row['namfisa_levy_due'] - (float) $row['namfisa_levy_paid'], 2),
            'duty_stamp' => round((float) $row['duty_stamp_due'] - (float) $row['duty_stamp_paid'], 2),
            'principal' => round((float) $row['principal_due'] - (float) $row['principal_paid'], 2),
        ];

        $allocated = ['penalty' => 0.0, 'interest' => 0.0, 'fees' => 0.0, 'namfisa_levy' => 0.0, 'duty_stamp' => 0.0, 'principal' => 0.0];

        // Installment components are paid off first; only amounts paid
        // beyond the full installment flow into the penalty leg -- a
        // payment equal to the installment must fully clear it and
        // leave any outstanding penalty untouched (still Penalty
        // Receivable, not recognized as Penalty Income).
        foreach (['interest', 'fees', 'namfisa_levy', 'duty_stamp', 'principal', 'penalty'] as $component) {
            if ($remaining <= 0.009 || $outstanding[$component] <= 0.009) {
                continue;
            }
            $take = min($remaining, $outstanding[$component]);
            $allocated[$component] = round($take, 2);
            $remaining = round($remaining - $take, 2);
        }

        $totalAllocated = round(array_sum($allocated), 2);
        if ($totalAllocated <= 0.009) {
            return $remaining;
        }

        foreach ($allocated as $component => $value) {
            $totals[$component] += $value;
        }

        $newPrincipalPaid = round((float) $row['principal_paid'] + $allocated['principal'], 2);
        $newInterestPaid = round((float) $row['interest_paid'] + $allocated['interest'], 2);
        $newFeesPaid = round((float) $row['fees_paid'] + $allocated['fees'], 2);
        $newLevyPaid = round((float) $row['namfisa_levy_paid'] + $allocated['namfisa_levy'], 2);
        $newStampPaid = round((float) $row['duty_stamp_paid'] + $allocated['duty_stamp'], 2);
        $newPenaltyPaid = round((float) $row['penalty_paid'] + $allocated['penalty'], 2);
        $newTotalPaid = round($newPrincipalPaid + $newInterestPaid + $newFeesPaid + $newLevyPaid + $newStampPaid + $newPenaltyPaid, 2);
        $isPaid = $newTotalPaid >= round((float) $row['total_due'], 2) - 0.01;

        $this->update('loan_schedules', [
            'principal_paid' => $newPrincipalPaid,
            'interest_paid' => $newInterestPaid,
            'fees_paid' => $newFeesPaid,
            'namfisa_levy_paid' => $newLevyPaid,
            'duty_stamp_paid' => $newStampPaid,
            'penalty_paid' => $newPenaltyPaid,
            'total_paid' => $newTotalPaid,
            'status' => $isPaid ? 'Paid' : 'Partial',
            'paid_at' => $isPaid ? date('Y-m-d H:i:s') : null,
        ], 'id', $row['id']);

        $this->insert('payment_allocations', [
            'payment_id' => $paymentId,
            'loan_id' => $loanId,
            'schedule_id' => $row['id'],
            'principal_allocated' => $allocated['principal'],
            'interest_allocated' => $allocated['interest'],
            'fees_allocated' => $allocated['fees'],
            'namfisa_levy_allocated' => $allocated['namfisa_levy'],
            'duty_stamp_allocated' => $allocated['duty_stamp'],
            'penalty_allocated' => $allocated['penalty'],
            'total_allocated' => $totalAllocated,
        ]);

        return $remaining;
    }

    /**
     * Shared tail of both allocation paths: stamps the payment's own
     * component totals, posts commission and the accounting journal,
     * settles any now-fully-paid penalty, and rolls the loan's own status
     * (Completed/Current) up from the schedule's remaining state.
     */
    private function finalizeAllocation(array $loan, int $paymentId, float $amount, array $totals, float $remaining, ?int $userId, ?int $bankAccountId, ?string $paymentDate): void
    {
        $this->update('payments', [
            'principal_amount' => round($totals['principal'], 2),
            'interest_amount' => round($totals['interest'], 2),
            'fees_amount' => round($totals['fees'], 2),
            'namfisa_levy_amount' => round($totals['namfisa_levy'], 2),
            'duty_stamp_amount' => round($totals['duty_stamp'], 2),
            'penalty_amount' => round($totals['penalty'], 2),
            'overpayment_amount' => round($remaining, 2),
        ], 'id', $paymentId);

        \App\Services\AgentCommissionService::onPaymentAllocated($loan, $paymentId, $totals['interest'], $userId);

        $this->postCollectionAccounting($loan, $paymentId, $amount, $totals, $remaining, $userId, $bankAccountId, $paymentDate);

        if ($totals['penalty'] > 0) {
            (new Penalty())->markPaidWhereSettled((int) $loan['id']);
        }

        $outstandingCount = (int) $this->scalar(
            "SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ? AND status != 'Paid'",
            [$loan['id']]
        );

        if ($outstandingCount === 0) {
            $this->update('loans', ['loan_status' => 'Completed'], 'id', $loan['id']);
            $this->insert('loan_status_history', [
                'loan_id' => $loan['id'],
                'old_status' => $loan['loan_status'],
                'new_status' => 'Completed',
                'notes' => 'All installments paid in full.',
                'changed_by' => $userId,
            ]);
        }

        // Loan stays 'Active' for its whole disbursed life -- lifecycle
        // ('Active') and payment condition ('Current'/'In Arrears') are
        // separate dimensions now (see ArrearsService::refreshLoanStatus());
        // the old one-way flip to loan_status='Current' on first payment is
        // retired.
        \App\Services\ArrearsService::refreshLoanStatus(
            (int) $loan['id'],
            $paymentDate ?: date('Y-m-d'),
            $userId,
            'Payment',
            'PAYMENT:' . $paymentId
        );
    }

    /**
     * Dr Bank Account (full amount received)
     *   Cr Loans Receivable       (principal collected -- relieves the receivable booked at disbursement)
     *   Cr Interest Receivable    (interest collected -- relieves the receivable raised by the interest
     *                              accrual run; interest income was already recognized when it was
     *                              accrued, so collecting it never recognizes income a second time)
     *   Cr NAMFISA Levy Receivable / Cr Stamp Duty Receivable (levy/stamp collected -- same relief;
     *                              the matching Payable side doesn't move here, it was already fully
     *                              booked at disbursement and settles separately when actually remitted)
     *   Cr Admin Fee Income       (fees collected)
     *   Cr Penalty Receivable     (penalty collected -- relieves the receivable raised by the
     *                              penalty accrual run; penalty income was already recognized when
     *                              the penalty was charged, so collecting it never recognizes income
     *                              a second time)
     *   Cr Refunds Payable        (any amount left over after clearing the whole schedule --
     *                              an overpayment owed back to the borrower)
     */
    private function postCollectionAccounting(array $loan, int $paymentId, float $amount, array $totals, float $overpayment, ?int $userId, ?int $bankAccountId = null, ?string $paymentDate = null): void
    {
        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();

        $bankGlAccount = $accounts->idByCode('1010');
        $bankLabel = 'Bank Account';
        if ($bankAccountId) {
            $bankAccount = (new BankAccount())->find($bankAccountId);
            if ($bankAccount) {
                $bankGlAccount = (int) $bankAccount['account_id'];
                $bankLabel = $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'];
            }
        }

        $lines = [
            [
                'account_id' => $bankGlAccount,
                'debit' => $amount,
                'credit' => 0,
                'description' => 'Payment received into ' . $bankLabel . ' for ' . $loan['loan_no'],
            ],
        ];

        if ($totals['principal'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('1020'),
                'debit' => 0,
                'credit' => round($totals['principal'], 2),
                'description' => 'Loan receivable settled for ' . $loan['loan_no'],
            ];
        }
        if ($totals['namfisa_levy'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('1051'),
                'debit' => 0,
                'credit' => round($totals['namfisa_levy'], 2),
                'description' => 'NAMFISA levy receivable settled for ' . $loan['loan_no'],
            ];
        }
        if ($totals['duty_stamp'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('1060'),
                'debit' => 0,
                'credit' => round($totals['duty_stamp'], 2),
                'description' => 'Stamp duty receivable settled for ' . $loan['loan_no'],
            ];
        }
        if ($totals['interest'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('1030'),
                'debit' => 0,
                'credit' => $totals['interest'],
                'description' => 'Interest receivable settled for ' . $loan['loan_no'],
            ];
        }
        if ($totals['fees'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('4030'),
                'debit' => 0,
                'credit' => $totals['fees'],
                'description' => 'Admin fee income for ' . $loan['loan_no'],
            ];
        }
        if ($totals['penalty'] > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('1040'),
                'debit' => 0,
                'credit' => $totals['penalty'],
                'description' => 'Penalty receivable settled for ' . $loan['loan_no'],
            ];
        }
        if ($overpayment > 0) {
            $lines[] = [
                'account_id' => $accounts->idByCode('2020'),
                'debit' => 0,
                'credit' => $overpayment,
                'description' => 'Overpayment on ' . $loan['loan_no'],
            ];
        }

        $journal->post(
            'PAYMENT_RECEIVED',
            'payments',
            $paymentId,
            $loan['loan_no'],
            'Payment collected for ' . $loan['loan_no'],
            $lines,
            $userId,
            $paymentDate
        );
    }
}
