<?php

namespace App\Services;

use App\Core\Database;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\LoanTopup;
use App\Models\StatutoryCharge;

/**
 * A top-up on a loan disbursed this same calendar month, with nothing paid
 * against it yet, is consolidated into that same loan (one balance, fresh
 * schedule for the combined principal) rather than created as a second loan
 * -- see shouldConsolidate(). Consolidation must only post the INCREMENTAL
 * disbursement: the original portion's Dr Loans Receivable / Cr Bank / Cr
 * NAMFISA Levy / Cr Duty Stamp entry already posted when the loan was first
 * released. So this generates a fresh full schedule for the combined
 * principal, compares its levy/stamp figures against what was already
 * posted, and posts only the difference -- while updating the levy/stamp
 * transaction rows to the new cumulative totals so the loan's statutory
 * records always reflect the true full amount.
 */
class TopUpService
{
    public static function isSameMonth(string $existingStartDate, string $requestDate): bool
    {
        return date('Y-m', strtotime($existingStartDate)) === date('Y-m', strtotime($requestDate));
    }

    public static function hasAnyPayment(int $loanId): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ? AND status IN ('Paid', 'Partial')");
        $stmt->execute([$loanId]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public static function shouldConsolidate(array $existingLoan, string $requestDate): bool
    {
        return self::isSameMonth($existingLoan['start_date'], $requestDate)
            && !self::hasAnyPayment((int) $existingLoan['id']);
    }

    /**
     * Merges a top-up amount into an already-active loan with no payments
     * posted yet. Returns the (unchanged) id of the consolidated loan.
     */
    public static function consolidate(
        array $existingLoan,
        array $product,
        array $plan,
        float $topupAmount,
        string $requestDate,
        ?array $bankAccount,
        int $userId
    ): int {
        $db = Database::connection();
        $loanModel = new Loan();
        $statutoryCharges = new StatutoryCharge();
        $accounts = new AccountingAccount();
        $journal = new AccountingJournal();
        $topups = new LoanTopup();

        $loanId = (int) $existingLoan['id'];
        $newPrincipal = round((float) $existingLoan['principal_amount'] + $topupAmount, 2);

        $namfisaRate = $statutoryCharges->currentNamfisaLevyRate();
        $dutyStampAmount = $statutoryCharges->currentDutyStampAmount();

        // Fresh full schedule for the combined principal, dated from the
        // loan's original start_date so the repayment cadence is unbroken.
        $schedule = LoanScheduleService::generate(
            $newPrincipal,
            (int) $plan['months'],
            (float) $plan['interest_rate'],
            (float) $plan['admin_fee'],
            $product['interest_method'],
            $existingLoan['start_date'],
            $namfisaRate,
            $dutyStampAmount
        );

        $levyTxn = $statutoryCharges->findNamfisaLevyByLoan($loanId);
        $stampTxn = $statutoryCharges->findDutyStampByLoan($loanId);
        // Preserved for the loan_topups snapshot below -- $levyTxn/$stampTxn
        // themselves get reassigned further down once a new row is created.
        $levyTxnBefore = $levyTxn;
        $stampTxnBefore = $stampTxn;
        $alreadyPostedLevy = $levyTxn ? (float) $levyTxn['levy_amount'] : 0.0;
        $alreadyPostedStamp = $stampTxn ? (float) $stampTxn['stamp_amount'] : 0.0;
        $incrementalLevy = max(0, round($schedule['namfisa_levy'] - $alreadyPostedLevy, 2));
        $incrementalStamp = max(0, round($schedule['duty_stamp'] - $alreadyPostedStamp, 2));

        $db->beginTransaction();

        try {
            $loanReceivable = $accounts->idByCode('1020');
            $bankGlAccount = $bankAccount ? (int) $bankAccount['account_id'] : $accounts->idByCode('1010');
            $bankLabel = $bankAccount ? $bankAccount['bank_name'] . ' - ' . $bankAccount['account_name'] : 'Bank Account';
            $levyPayable = $accounts->idByCode('2030');
            $stampPayable = $accounts->idByCode('2040');

            $lines = [
                [
                    'account_id' => $loanReceivable,
                    'debit' => round($topupAmount + $incrementalLevy + $incrementalStamp, 2),
                    'credit' => 0,
                    'description' => 'Top-up loan receivable for ' . $existingLoan['loan_no'],
                ],
                [
                    'account_id' => $bankGlAccount,
                    'debit' => 0,
                    'credit' => $topupAmount,
                    'description' => 'Top-up disbursed from ' . $bankLabel . ' for ' . $existingLoan['loan_no'],
                ],
            ];
            if ($incrementalLevy > 0) {
                $lines[] = [
                    'account_id' => $levyPayable,
                    'debit' => 0,
                    'credit' => $incrementalLevy,
                    'description' => 'Incremental NAMFISA levy withheld for ' . $existingLoan['loan_no'] . ' top-up',
                ];
            }
            if ($incrementalStamp > 0) {
                $lines[] = [
                    'account_id' => $stampPayable,
                    'debit' => 0,
                    'credit' => $incrementalStamp,
                    'description' => 'Incremental duty stamp withheld for ' . $existingLoan['loan_no'] . ' top-up',
                ];
            }

            $journalId = $journal->post(
                'LOAN_TOPUP',
                'loans',
                $loanId,
                $existingLoan['loan_no'],
                'Loan topped up by ' . format_money($topupAmount) . ': ' . $existingLoan['loan_no'],
                $lines,
                $userId
            );

            if ($levyTxn) {
                $statutoryCharges->updateNamfisaLevy($loanId, [
                    'levy_rate' => $namfisaRate,
                    'basis_amount' => $newPrincipal,
                    'levy_amount' => $schedule['namfisa_levy'],
                ]);
            } elseif ($schedule['namfisa_levy'] > 0) {
                $statutoryCharges->recordNamfisaLevy([
                    'loan_id' => $loanId,
                    'borrower_id' => (int) $existingLoan['borrower_id'],
                    'branch_id' => (int) $existingLoan['branch_id'],
                    'levy_date' => $requestDate,
                    'levy_rate' => $namfisaRate,
                    'basis_amount' => $newPrincipal,
                    'levy_amount' => $schedule['namfisa_levy'],
                    'status' => 'Calculated',
                ]);
                $levyTxn = $statutoryCharges->findNamfisaLevyByLoan($loanId);
            }

            if ($stampTxn) {
                $statutoryCharges->updateDutyStamp($loanId, [
                    'basis_amount' => $newPrincipal,
                    'stamp_amount' => $schedule['duty_stamp'],
                ]);
            } elseif ($schedule['duty_stamp'] > 0) {
                $statutoryCharges->recordDutyStamp([
                    'loan_id' => $loanId,
                    'borrower_id' => (int) $existingLoan['borrower_id'],
                    'branch_id' => (int) $existingLoan['branch_id'],
                    'stamp_date' => $requestDate,
                    'basis_amount' => $newPrincipal,
                    'stamp_amount' => $schedule['duty_stamp'],
                    'status' => 'Calculated',
                ]);
                $stampTxn = $statutoryCharges->findDutyStampByLoan($loanId);
            }

            // Snapshot the pre-topup state (schedule rows, statutory-charge
            // rows exactly as fetched above, before any of this method's
            // writes) so a mistaken top-up can be fully undone later.
            $previousScheduleRows = $loanModel->schedule($loanId);

            // Zero payments posted (guaranteed by shouldConsolidate()'s gate
            // before this method is ever called), so the whole schedule can
            // be safely replaced rather than merged row-by-row.
            $del = $db->prepare("DELETE FROM loan_schedules WHERE loan_id = ?");
            $del->execute([$loanId]);
            $loanModel->insertScheduleRows($loanId, $schedule['rows']);

            $loanModel->updateFields($loanId, [
                'product_id' => (int) $product['id'],
                'plan_id' => (int) $plan['id'],
                'principal_amount' => $newPrincipal,
                'interest_amount' => $schedule['interest_amount'],
                'admin_fee' => $schedule['admin_fee'],
                'total_payable' => $schedule['total_payable'],
                'installment_amount' => $schedule['installment_amount'],
                'term_months' => (int) $plan['months'],
                'interest_rate' => (float) $plan['interest_rate'],
                'penalty_rate' => (float) $plan['penalty_rate'],
                'maturity_date' => end($schedule['rows'])['due_date'] ?? null,
            ]);

            $loanModel->logStatus(
                $loanId,
                $existingLoan['loan_status'],
                $existingLoan['loan_status'],
                $userId,
                'Topped up by ' . format_money($topupAmount) . ', consolidated into this loan. New principal: ' . format_money($newPrincipal) . '.'
            );

            $disbursementId = $loanModel->createDisbursement([
                'loan_id' => $loanId,
                'borrower_id' => (int) $existingLoan['borrower_id'],
                'disbursement_no' => generate_reference('DSB'),
                'disbursement_date' => date('Y-m-d'),
                'disbursement_method' => $bankAccount ? 'Bank Transfer' : 'Other',
                'bank_account_id' => $bankAccount ? $bankAccount['id'] : null,
                'amount' => $topupAmount,
                'reference_no' => null,
                'status' => 'Disbursed',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'disbursed_by' => $userId,
                'disbursed_at' => date('Y-m-d H:i:s'),
                'created_by' => $userId,
            ]);

            if ($incrementalLevy > 0 && $levyTxn) {
                $statutoryCharges->markNamfisaLevyPosted($loanId, $journalId);
            }
            if ($incrementalStamp > 0 && $stampTxn) {
                $statutoryCharges->markDutyStampPosted($loanId, $journalId);
            }

            $topups->create([
                'loan_id' => $loanId,
                'topup_amount' => $topupAmount,
                'journal_id' => $journalId,
                'disbursement_id' => $disbursementId,
                'previous_loan_snapshot' => json_encode($existingLoan),
                'previous_schedule_snapshot' => json_encode($previousScheduleRows),
                'previous_namfisa_levy_snapshot' => $levyTxnBefore ? json_encode($levyTxnBefore) : null,
                'previous_duty_stamp_snapshot' => $stampTxnBefore ? json_encode($stampTxnBefore) : null,
                'created_by' => $userId,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $loanId;
    }

    /**
     * Undoes a consolidation: reverses the incremental journal and restores
     * the loan/schedule/statutory-charge state to exactly what was
     * snapshotted immediately before that top-up. Only ever safe for the
     * most recent unreversed top-up on the loan, and only while nothing has
     * been paid against the (consolidated) schedule since -- both checked
     * here, not just relied upon from the caller.
     */
    public static function reverseConsolidation(array $topup, int $userId): void
    {
        $db = Database::connection();
        $loanModel = new Loan();
        $statutoryCharges = new StatutoryCharge();
        $journal = new AccountingJournal();
        $topups = new LoanTopup();

        $loanId = (int) $topup['loan_id'];

        if ($topup['status'] !== 'Active') {
            throw new \RuntimeException('This top-up has already been reversed.');
        }

        $latest = $topups->latestActiveForLoan($loanId);
        if (!$latest || (int) $latest['id'] !== (int) $topup['id']) {
            throw new \RuntimeException('Only the most recent top-up on a loan can be reversed.');
        }

        if (self::hasAnyPayment($loanId)) {
            throw new \RuntimeException('This loan has a payment recorded since the top-up, so it can no longer be reversed.');
        }

        $previousLoan = json_decode((string) $topup['previous_loan_snapshot'], true);
        $previousSchedule = json_decode((string) $topup['previous_schedule_snapshot'], true);
        $previousLevy = $topup['previous_namfisa_levy_snapshot'] ? json_decode((string) $topup['previous_namfisa_levy_snapshot'], true) : null;
        $previousStamp = $topup['previous_duty_stamp_snapshot'] ? json_decode((string) $topup['previous_duty_stamp_snapshot'], true) : null;

        $db->beginTransaction();

        try {
            if ($topup['journal_id']) {
                $journal->reverse((int) $topup['journal_id'], $userId);
            }

            $del = $db->prepare("DELETE FROM loan_schedules WHERE loan_id = ?");
            $del->execute([$loanId]);
            $loanModel->insertScheduleRows($loanId, $previousSchedule);

            $loanModel->updateFields($loanId, [
                'product_id' => (int) $previousLoan['product_id'],
                'plan_id' => (int) $previousLoan['plan_id'],
                'principal_amount' => $previousLoan['principal_amount'],
                'interest_amount' => $previousLoan['interest_amount'],
                'admin_fee' => $previousLoan['admin_fee'],
                'total_payable' => $previousLoan['total_payable'],
                'installment_amount' => $previousLoan['installment_amount'],
                'term_months' => (int) $previousLoan['term_months'],
                'interest_rate' => $previousLoan['interest_rate'],
                'penalty_rate' => $previousLoan['penalty_rate'],
                'maturity_date' => $previousLoan['maturity_date'],
            ]);

            if ($previousLevy) {
                $statutoryCharges->updateNamfisaLevy($loanId, [
                    'levy_rate' => $previousLevy['levy_rate'],
                    'basis_amount' => $previousLevy['basis_amount'],
                    'levy_amount' => $previousLevy['levy_amount'],
                    'status' => $previousLevy['status'],
                    'journal_id' => $previousLevy['journal_id'],
                ]);
            } else {
                $noLevy = $db->prepare("DELETE FROM namfisa_levy_transactions WHERE loan_id = ?");
                $noLevy->execute([$loanId]);
            }

            if ($previousStamp) {
                $statutoryCharges->updateDutyStamp($loanId, [
                    'basis_amount' => $previousStamp['basis_amount'],
                    'stamp_amount' => $previousStamp['stamp_amount'],
                    'status' => $previousStamp['status'],
                    'journal_id' => $previousStamp['journal_id'],
                ]);
            } else {
                $noStamp = $db->prepare("DELETE FROM duty_stamp_transactions WHERE loan_id = ?");
                $noStamp->execute([$loanId]);
            }

            if ($topup['disbursement_id']) {
                $cancelDisbursement = $db->prepare("UPDATE loan_disbursements SET status = 'Cancelled' WHERE id = ?");
                $cancelDisbursement->execute([(int) $topup['disbursement_id']]);
            }

            $topups->markReversed((int) $topup['id'], $userId);

            $loanModel->logStatus(
                $loanId,
                $previousLoan['loan_status'] ?? null,
                $previousLoan['loan_status'] ?? null,
                $userId,
                'Top-up of ' . format_money((float) $topup['topup_amount']) . ' reversed; loan restored to its terms from before that top-up.'
            );

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
