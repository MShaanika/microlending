<?php

namespace App\Services;

use App\Core\Database;

/**
 * Builds a chronological transaction ledger for a loan -- disbursement,
 * every posted payment, every penalty charge, and every missed due date,
 * each with the running balance the borrower still owes after that event.
 * This is the actual "statement of account" content; the existing invoice
 * view only ever showed the planned schedule, not what actually happened
 * and when.
 *
 * The opening balance is the loan's original contractual amount (principal
 * + interest + fees + NAMFISA levy + duty stamp) taken from the schedule's
 * own due columns, which never change after creation -- only penalty_due
 * and the *_paid tracking columns move later, so summing those columns is
 * immune to penalties added after disbursement.
 *
 * "Payment Missed" events are a neutral factual record ("nothing had
 * arrived against this installment by its due date"), not a business rule
 * -- unlike PenaltyAccrualService::GRACE_DAYS, there's no grace buffer
 * here, and the event isn't retracted if a late payment eventually shows
 * up (that just adds its own, separate "Payment received" line later).
 */
class LoanStatementService
{
    public static function ledger(int $loanId): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(principal_due + interest_due + fees_due + namfisa_levy_due + duty_stamp_due), 0) AS total
             FROM loan_schedules WHERE loan_id = ?"
        );
        $stmt->execute([$loanId]);
        $openingBalance = round((float) $stmt->fetchColumn(), 2);

        $disbursement = $db->prepare(
            "SELECT disbursement_date, amount, disbursement_method, reference_no
             FROM loan_disbursements WHERE loan_id = ? AND status = 'Disbursed' ORDER BY disbursement_date LIMIT 1"
        );
        $disbursement->execute([$loanId]);
        $disbursementRow = $disbursement->fetch();

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

        $today = date('Y-m-d');
        $missed = $db->prepare(
            "SELECT ls.installment_no, ls.due_date
             FROM loan_schedules ls
             WHERE ls.loan_id = ? AND ls.due_date <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM payment_allocations pa
                   JOIN payments p ON p.id = pa.payment_id
                   WHERE pa.schedule_id = ls.id AND p.status = 'Posted' AND p.payment_date <= ls.due_date
               )
             ORDER BY ls.installment_no"
        );
        $missed->execute([$loanId, $today]);
        $missedRows = $missed->fetchAll();

        $events = [];

        if ($disbursementRow) {
            $events[] = [
                'date' => $disbursementRow['disbursement_date'],
                'type' => 'Disbursement',
                'description' => 'Loan disbursed (' . $disbursementRow['disbursement_method'] . ')'
                    . ($disbursementRow['reference_no'] ? ' - Ref ' . $disbursementRow['reference_no'] : ''),
                'debit' => $openingBalance,
                'credit' => 0.0,
            ];
        } else {
            // No disbursement record found (e.g. legacy/edge case) -- still
            // seed the ledger with the contractual opening balance so the
            // running total is correct from the first real transaction.
            $events[] = [
                'date' => null,
                'type' => 'Opening Balance',
                'description' => 'Opening balance',
                'debit' => $openingBalance,
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

        foreach ($missedRows as $m) {
            $events[] = [
                'date' => $m['due_date'],
                'type' => 'Missed',
                'description' => 'Payment missed - Installment ' . $m['installment_no'],
                'debit' => 0.0,
                'credit' => 0.0,
            ];
        }

        usort($events, function ($a, $b) {
            $dateCompare = strcmp((string) $a['date'], (string) $b['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            // Disbursement/opening always sorts first on its date.
            $rank = fn ($e) => $e['type'] === 'Disbursement' || $e['type'] === 'Opening Balance' ? 0 : 1;
            return $rank($a) <=> $rank($b);
        });

        $runningBalance = 0.0;
        foreach ($events as &$event) {
            $runningBalance = round($runningBalance + $event['debit'] - $event['credit'], 2);
            $event['balance'] = $runningBalance;
        }
        unset($event);

        return [
            'opening_balance' => $openingBalance,
            'events' => $events,
            'closing_balance' => $runningBalance,
        ];
    }
}
