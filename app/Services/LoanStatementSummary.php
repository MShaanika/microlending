<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanReschedule;

/**
 * The headline figures of a loan, worked out ONE way from the same loan row, schedule and transaction
 * history (LoanStatementService::ledger / CombinedLoanService), so the loan page, the statement on screen,
 * the client portal, the PDF (download and e-mail) and the Excel file all say the same thing:
 *
 *  - Principal: the money paid out to the client (loans.principal_amount).
 *  - Charges: the NAMFISA levy and stamp duty. They are ADDED to the principal the client repays, so the
 *    schedule's Principal column already includes them; they are not extra fees on top of the schedule.
 *  - Opening balance = principal + charges = the schedule's first opening balance = where the transaction
 *    history stands once the charges are added.
 *  - Total payable = opening balance + interest + admin fee.
 *  - Still to pay = schedule total due - paid, which must equal the transaction history's closing balance.
 *
 * `problems` lists, in plain words, every figure that does not agree; staff screens show them.
 */
class LoanStatementSummary
{
    private const TOLERANCE = 0.01;

    /** build() for a stored loan: also knows whether the loan (or, for a combined loan, any part of it) was rescheduled. */
    public static function forLoan(array $loan, array $schedule, array $ledger, array $loanIds = []): array
    {
        $reschedules = new LoanReschedule();
        $rescheduled = false;
        foreach ($loanIds ?: [(int) ($loan['id'] ?? 0)] as $id) {
            if ((int) $id > 0 && $reschedules->hasImplementedReschedule((int) $id)) {
                $rescheduled = true;
                break;
            }
        }
        return self::build($loan, $schedule, $ledger, $rescheduled);
    }

    /** @param bool $rescheduled a reschedule replaces the schedule and total payable, so the "adds up to total payable" checks no longer apply */
    public static function build(array $loan, array $schedule, array $ledger, bool $rescheduled = false): array
    {
        $principal = round((float) ($loan['principal_amount'] ?? 0), 2);
        $levy = round((float) ($ledger['levy'] ?? 0), 2);
        $stamp = round((float) ($ledger['stamp'] ?? 0), 2);
        $charges = round($levy + $stamp, 2);
        $opening = round($principal + $charges, 2);
        $interest = round((float) ($loan['interest_amount'] ?? 0), 2);
        $adminFee = round((float) ($loan['admin_fee'] ?? 0), 2);
        $totalPayable = round((float) ($loan['total_payable'] ?? 0), 2);

        $sum = fn (string $col) => round(array_sum(array_map('floatval', array_column($schedule, $col))), 2);
        $scheduleDue = $sum('total_due');
        $schedulePaid = $sum('total_paid');
        $penalties = $sum('penalty_due');
        $schedulePrincipal = $sum('principal_due');

        $paidOut = array_key_exists('paid_out', $ledger) ? (bool) $ledger['paid_out'] : !empty($ledger['events']);
        $problems = [];
        $m = fn (float $v) => number_format($v, 2);

        if ($paidOut && abs((float) ($ledger['closing_balance'] ?? 0) - round($scheduleDue - $schedulePaid, 2)) > self::TOLERANCE) {
            $problems[] = 'The transaction history ends at ' . $m((float) $ledger['closing_balance']) . ' but the schedule says '
                . $m($scheduleDue - $schedulePaid) . ' is still to pay. A reschedule, or a payment that was not fully allocated to the schedule,'
                . ' is the usual cause: the history does not show that change.';
        }
        if (!$rescheduled && abs(round($opening + $interest + $adminFee, 2) - $totalPayable) > self::TOLERANCE) {
            $problems[] = 'Principal ' . $m($principal) . ' + charges ' . $m($charges) . ' + interest ' . $m($interest)
                . ($adminFee > 0 ? ' + admin fee ' . $m($adminFee) : '') . ' does not add up to the total payable of ' . $m($totalPayable) . '.';
        }
        if (!$rescheduled && $schedule !== [] && abs(round($scheduleDue - $penalties, 2) - $totalPayable) > self::TOLERANCE) {
            $problems[] = 'The schedule adds up to ' . $m($scheduleDue - $penalties) . ' (before penalties) but the total payable is ' . $m($totalPayable) . '.';
        }

        // A reschedule resets total payable to its new schedule but never touches loans.interest_amount, so the printed
        // "opening balance + interest (+ admin fee) = total payable" sum would not add up: show the interest as it now stands.
        $interestShown = $rescheduled ? round($totalPayable - $opening - $adminFee, 2) : $interest;

        return [
            'principal' => $principal,
            'levy' => $levy,
            'stamp' => $stamp,
            'charges' => $charges,
            'opening_balance' => $opening,
            'interest' => $interestShown,
            'rescheduled' => $rescheduled,
            // Labels for every output (loan page, statement, portal, PDF, Excel): before the payout nothing has been paid out or added yet.
            'principal_label' => $paidOut ? 'Principal (paid out)' : 'Principal (to be paid out)',
            'levy_label' => $paidOut ? '+ NAMFISA levy' : '+ NAMFISA levy (added when paid out)',
            'stamp_label' => $paidOut ? '+ Stamp duty' : '+ Stamp duty (added when paid out)',
            'opening_label' => $paidOut ? 'Opening balance' : 'Opening balance (once paid out)',
            'interest_label' => $rescheduled ? '+ Interest (as rescheduled)' : '+ Interest',
            'admin_fee' => $adminFee,
            'total_payable' => $totalPayable,
            'schedule_principal' => $schedulePrincipal,
            'schedule_due' => $scheduleDue,
            'paid' => $schedulePaid,
            'penalties' => $penalties,
            // nothing is owed before the money has left
            'outstanding' => $paidOut ? round($scheduleDue - $schedulePaid, 2) : 0.0,
            'paid_out' => $paidOut,
            'disbursed_on' => $ledger['disbursed_on'] ?? null,
            'awaiting_payout' => ($loan['loan_status'] ?? '') === Loan::STATUS_AWAITING_PAYOUT,
            'problems' => $problems,
        ];
    }

    /** The line under the schedule's Total row: what is still to pay, or (before the payout) that nothing is owed yet. */
    public static function stillToPayLine(array $s): string
    {
        if (!$s['paid_out']) {
            return 'Nothing is owed yet: the loan has not been paid out. To repay once it is: ' . number_format($s['schedule_due'], 2);
        }
        return 'Still to pay (Total Due - Paid): ' . number_format($s['outstanding'], 2);
    }

    /** One plain sentence under the schedule saying what its Principal and Fees columns hold. */
    public static function scheduleNote(array $s): string
    {
        $m = fn (float $v) => number_format($v, 2);
        $parts = [];
        if ($s['levy'] > 0) {
            $parts[] = 'NAMFISA levy ' . $m($s['levy']);
        }
        if ($s['stamp'] > 0) {
            $parts[] = 'stamp duty ' . $m($s['stamp']);
        }
        $paidOut = !empty($s['paid_out']);
        $note = '';
        if ($parts !== []) {
            $note = $paidOut
                ? 'The Principal column includes the ' . implode(' and ', $parts) . ' added to the ' . $m($s['principal'])
                    . ' paid out, so the opening balance is ' . $m($s['opening_balance']) . '.'
                : 'The Principal column includes the ' . implode(' and ', $parts) . ' that will be added to the ' . $m($s['principal'])
                    . ' when it is paid out, so the opening balance will be ' . $m($s['opening_balance']) . '. Nothing has been paid out or charged yet.';
            $note .= ' These are statutory charges remitted to the Namibian authorities; they are not charged again under Fees. ';
        } elseif (!$paidOut) {
            $note = 'Nothing has been paid out or charged yet. ';
        }
        if ($s['admin_fee'] > 0) {
            $note .= 'Fees is the admin fee (' . $m($s['admin_fee']) . '). ';
        }
        $note .= 'Opening and Closing Principal are the principal still owed before and after each instalment; interest is not included in them.';
        if (!empty($s['rescheduled'])) {
            $note .= ' This loan was rescheduled: the schedule and total payable (' . $m($s['total_payable']) . ') are the rescheduled ones,'
                . ' and the transaction history does not show the change the reschedule made.';
        }
        return $note;
    }
}
