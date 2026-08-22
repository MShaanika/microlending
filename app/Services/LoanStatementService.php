<?php

namespace App\Services;

use App\Core\Database;

/**
 * Builds a chronological transaction ledger for a loan -- the disbursed
 * principal, the full-accrual interest/NAMFISA levy/duty stamp charge
 * (each its own line), every penalty, and every posted payment -- each
 * with the running balance the borrower still owes after that event.
 * This is the actual "statement of account" content; the existing
 * invoice view only ever showed the planned schedule, not what actually
 * happened and when.
 *
 * Interest/levy/stamp appear ONCE each, dated to disbursement, not spread
 * per-installment -- this deliberately mirrors
 * LoanController::postDisbursementAccounting(), which books the loan's
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

        $loanStmt = $db->prepare("SELECT interest_amount FROM loans WHERE id = ?");
        $loanStmt->execute([$loanId]);
        $loan = $loanStmt->fetch();
        $interestTotal = round((float) ($loan['interest_amount'] ?? 0), 2);

        $principalStmt = $db->prepare("SELECT COALESCE(SUM(principal_due), 0) FROM loan_schedules WHERE loan_id = ?");
        $principalStmt->execute([$loanId]);
        $principalTotal = round((float) $principalStmt->fetchColumn(), 2);

        $disbursement = $db->prepare(
            "SELECT disbursement_date, amount, disbursement_method, reference_no
             FROM loan_disbursements WHERE loan_id = ? AND status = 'Disbursed' ORDER BY disbursement_date LIMIT 1"
        );
        $disbursement->execute([$loanId]);
        $disbursementRow = $disbursement->fetch();
        $disbursementDate = $disbursementRow['disbursement_date'] ?? null;

        $levyStmt = $db->prepare("SELECT levy_rate, levy_amount FROM namfisa_levy_transactions WHERE loan_id = ? LIMIT 1");
        $levyStmt->execute([$loanId]);
        $levy = $levyStmt->fetch();

        $stampStmt = $db->prepare("SELECT stamp_amount FROM duty_stamp_transactions WHERE loan_id = ? LIMIT 1");
        $stampStmt->execute([$loanId]);
        $stamp = $stampStmt->fetch();

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
            'type' => 'Opening',
            'description' => 'Amount Disbursed'
                . ($disbursementRow && $disbursementRow['reference_no'] ? ' - Ref ' . $disbursementRow['reference_no'] : ''),
            'debit' => $principalTotal,
            'credit' => 0.0,
        ];

        if ($interestTotal > 0.009) {
            $events[] = [
                'date' => $disbursementDate,
                'type' => 'Interest',
                'description' => 'Interest charged',
                'debit' => $interestTotal,
                'credit' => 0.0,
            ];
        }

        if ($levy && (float) $levy['levy_amount'] > 0.009) {
            $rate = round((float) $levy['levy_rate'], 2);
            $events[] = [
                'date' => $disbursementDate,
                'type' => 'Fee',
                'description' => 'NAMFISA Levy charged' . ($rate > 0 ? ' (' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%)' : ''),
                'debit' => round((float) $levy['levy_amount'], 2),
                'credit' => 0.0,
            ];
        }

        if ($stamp && (float) $stamp['stamp_amount'] > 0.009) {
            $events[] = [
                'date' => $disbursementDate,
                'type' => 'Fee',
                'description' => 'Stamp Duty charged',
                'debit' => round((float) $stamp['stamp_amount'], 2),
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

        usort($events, function ($a, $b) {
            $dateCompare = strcmp((string) $a['date'], (string) $b['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            // On a tied date: opening first, then the disbursement-time
            // charges, then penalties, then payments last.
            $rank = fn ($e) => match (true) {
                $e['type'] === 'Opening' => 0,
                $e['type'] === 'Interest' || $e['type'] === 'Fee' => 1,
                $e['type'] === 'Penalty' => 2,
                default => 3,
            };
            return $rank($a) <=> $rank($b);
        });

        $runningBalance = 0.0;
        foreach ($events as &$event) {
            $runningBalance = round($runningBalance + $event['debit'] - $event['credit'], 2);
            $event['balance'] = $runningBalance;
        }
        unset($event);

        return [
            'opening_balance' => $principalTotal,
            'events' => $events,
            'closing_balance' => $runningBalance,
        ];
    }
}
