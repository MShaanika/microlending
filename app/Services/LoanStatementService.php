<?php

namespace App\Services;

use App\Core\Database;

/**
 * Builds a chronological transaction ledger for a loan -- the disbursed
 * principal, the NAMFISA levy/stamp duty added to it (so the running balance
 * then equals the schedule's opening balance), the full-accrual interest and
 * admin fee (each its own line), every penalty, and every posted payment --
 * each with the running balance the borrower still owes after that event.
 * Its closing balance must equal the schedule's total due less paid
 * (LoanStatementSummary checks this).
 * This is the actual "statement of account" content; the existing
 * invoice view only ever showed the planned schedule, not what actually
 * happened and when.
 *
 * Interest/levy/stamp appear ONCE each, dated to disbursement, not spread
 * per-installment -- this deliberately mirrors
 * LoanReleaseService::postDisbursementAccounting(), which books the loan's
 * full interest/levy/stamp as a receivable in a single journal entry at
 * disbursement (full accrual), not recognized period by period. The
 * statement should read the same way the books actually work: what got
 * charged in that one disbursement transaction, then what came in against
 * it afterward (payments, penalties).
 *
 * There is deliberately no "Payment Missed" line -- that carried no
 * debit/credit amount, so it read as a transaction without moving any
 * money. Whether an installment is overdue is already visible from the
 * amortization schedule table above this one.
 */
class LoanStatementService
{
    public static function ledger(int $loanId): array
    {
        $db = Database::connection();

        $loanStmt = $db->prepare("SELECT principal_amount, interest_amount, admin_fee, loan_status FROM loans WHERE id = ?");
        $loanStmt->execute([$loanId]);
        $loan = $loanStmt->fetch();

        $levyStmt = $db->prepare("SELECT levy_rate, levy_amount FROM namfisa_levy_transactions WHERE loan_id = ? LIMIT 1");
        $levyStmt->execute([$loanId]);
        $levy = $levyStmt->fetch();

        $stampStmt = $db->prepare("SELECT stamp_amount FROM duty_stamp_transactions WHERE loan_id = ? LIMIT 1");
        $stampStmt->execute([$loanId]);
        $stamp = $stampStmt->fetch();

        // What the statement summary needs even when there are no transactions yet (LoanStatementSummary).
        $charges = [
            'levy' => $levy ? round((float) $levy['levy_amount'], 2) : 0.0,
            'stamp' => $stamp ? round((float) $stamp['stamp_amount'], 2) : 0.0,
        ];

        $disbursement = $db->prepare(
            "SELECT disbursement_date, amount, disbursement_method, reference_no
             FROM loan_disbursements WHERE loan_id = ? AND status = 'Disbursed' ORDER BY disbursement_date LIMIT 1"
        );
        $disbursement->execute([$loanId]);
        $disbursementRow = $disbursement->fetch();
        $disbursementDate = $disbursementRow['disbursement_date'] ?? null;

        // Released with a Collexia EnCr payout that Collexia has not confirmed yet: nothing has been
        // paid, posted or charged, so the statement has no transactions -- only a plain note.
        if (($loan['loan_status'] ?? '') === \App\Models\Loan::STATUS_AWAITING_PAYOUT) {
            $pending = $db->prepare("SELECT amount, encr_reference_no FROM loan_disbursements WHERE loan_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1");
            $pending->execute([$loanId]);
            $p = $pending->fetch() ?: [];
            return [
                'opening_balance' => 0.0,
                'events' => [],
                'closing_balance' => 0.0,
                'paid_out' => false,
                'disbursed_on' => null,
                'notice' => 'Payout pending with Collexia' . (!empty($p['encr_reference_no']) ? ', ref ' . $p['encr_reference_no'] : '')
                    . ' (' . format_money((float) ($p['amount'] ?? $loan['principal_amount'] ?? 0)) . '). Nothing has been paid out or charged yet: the amount disbursed, interest and fees'
                    . ' appear here once Collexia confirms the payment. The repayment schedule is the plan until then.',
            ] + $charges;
        }

        // Never released (still being approved, approved but not paid out, or denied): no money has
        // moved, so the statement must not show an "Amount Disbursed" line or any charge.
        if (!$disbursementRow && in_array($loan['loan_status'] ?? '', ['Pending Approval', 'Approved', 'Denied'], true)) {
            return [
                'opening_balance' => 0.0,
                'events' => [],
                'closing_balance' => 0.0,
                'paid_out' => false,
                'disbursed_on' => null,
                'notice' => 'This loan has not been paid out yet, so nothing has been charged. The repayment schedule is the plan until then.',
            ] + $charges;
        }

        $interestTotal = round((float) ($loan['interest_amount'] ?? 0), 2);
        $adminFee = round((float) ($loan['admin_fee'] ?? 0), 2);
        // The actual cash disbursed to the borrower -- NOT SUM(loan_schedules.principal_due),
        // which rolls the NAMFISA levy/duty stamp into what's owed per installment. Using that
        // sum here double-counted the levy/stamp, since they're also posted as their own Fee
        // lines below.
        $principalTotal = round((float) ($loan['principal_amount'] ?? 0), 2);

        $payments = $db->prepare(
            "SELECT p.id, p.payment_no, p.payment_date, p.payment_source, p.reference_no, p.amount_received,
                    GROUP_CONCAT(DISTINCT ls.installment_no ORDER BY ls.installment_no) AS installments
             FROM payments p
             LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
             LEFT JOIN loan_schedules ls ON ls.id = pa.schedule_id
             WHERE p.loan_id = ? AND p.status = 'Posted'
             GROUP BY p.id
             ORDER BY p.payment_date, p.id"
        );
        $payments->execute([$loanId]);
        $paymentRows = $payments->fetchAll();

        $penalties = $db->prepare(
            "SELECT penalty_no, penalty_date, penalty_amount, reason
             FROM penalties WHERE loan_id = ? AND status IN ('Charged', 'Paid') ORDER BY penalty_date, id"
        );
        $penalties->execute([$loanId]);
        $penaltyRows = $penalties->fetchAll();

        $events = [];

        $events[] = [
            'date' => $disbursementDate,
            'type' => 'Disbursed',
            'description' => 'Amount Disbursed'
                . ($disbursementRow && $disbursementRow['reference_no'] ? ' - Ref ' . $disbursementRow['reference_no'] : ''),
            'debit' => $principalTotal,
            'credit' => 0.0,
        ];

        // The NAMFISA levy and stamp duty are added to the amount the client repays: the schedule's
        // Principal column already includes them (LoanScheduleService). They come straight after the
        // payout, so the running balance then equals the schedule's opening balance.
        if ($charges['levy'] > 0.009) {
            $rate = round((float) $levy['levy_rate'], 2);
            $events[] = [
                'date' => $disbursementDate,
                'type' => 'Charge',
                'description' => 'NAMFISA levy' . ($rate > 0 ? ' (' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%)' : '') . ' added to the loan balance',
                'debit' => $charges['levy'],
                'credit' => 0.0,
            ];
        }

        if ($charges['stamp'] > 0.009) {
            $events[] = [
                'date' => $disbursementDate,
                'type' => 'Charge',
                'description' => 'Stamp duty added to the loan balance',
                'debit' => $charges['stamp'],
                'credit' => 0.0,
            ];
        }

        if ($interestTotal > 0.009) {
            $events[] = [
                'date' => $disbursementDate,
                'type' => 'Interest',
                'description' => 'Interest charged',
                'debit' => $interestTotal,
                'credit' => 0.0,
            ];
        }

        // The admin fee is part of Total Payable and of the schedule's Fees column, so it belongs here too.
        if ($adminFee > 0.009) {
            $events[] = [
                'date' => $disbursementDate,
                'type' => 'Fee',
                'description' => 'Admin fee charged',
                'debit' => $adminFee,
                'credit' => 0.0,
            ];
        }

        foreach ($paymentRows as $p) {
            $installmentLabel = '';
            if (!empty($p['installments'])) {
                $nums = explode(',', $p['installments']);
                $installmentLabel = ' - Installment' . (count($nums) > 1 ? 's ' : ' ') . implode(', ', $nums);
            }
            $events[] = [
                'date' => $p['payment_date'],
                'type' => 'Payment',
                'description' => 'Payment received (' . $p['payment_source'] . ')' . $installmentLabel
                    . ($p['reference_no'] ? ' - Ref ' . $p['reference_no'] : '') . ' - ' . $p['payment_no'],
                'debit' => 0.0,
                'credit' => round((float) $p['amount_received'], 2),
            ];
        }

        foreach ($penaltyRows as $pen) {
            $events[] = [
                'date' => $pen['penalty_date'],
                'type' => 'Penalty',
                'description' => 'Penalty charged - ' . $pen['reason'],
                'debit' => round((float) $pen['penalty_amount'], 2),
                'credit' => 0.0,
            ];
        }

        usort($events, [self::class, 'compareEvents']);

        $runningBalance = 0.0;
        foreach ($events as &$event) {
            $runningBalance = round($runningBalance + $event['debit'] - $event['credit'], 2);
            $event['balance'] = $runningBalance;
        }
        unset($event);

        return [
            // principal + levy + stamp: the same opening balance as the schedule's first row
            'opening_balance' => round($principalTotal + $charges['levy'] + $charges['stamp'], 2),
            'events' => $events,
            'closing_balance' => $runningBalance,
            'paid_out' => true,
            'disbursed_on' => $disbursementDate,
        ] + $charges;
    }

    /**
     * Date order; on a tied date: the payout first, then the levy/stamp added to it, then interest and
     * fees, then penalties, then payments last. ('Opening' is the payout's older type name.)
     */
    public static function compareEvents(array $a, array $b): int
    {
        $dateCompare = strcmp((string) $a['date'], (string) $b['date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        $rank = fn ($e) => match ($e['type']) {
            'Disbursed', 'Opening' => 0,
            'Charge' => 1,
            'Interest', 'Fee' => 2,
            'Penalty' => 3,
            default => 4,
        };
        return $rank($a) <=> $rank($b);
    }
}
