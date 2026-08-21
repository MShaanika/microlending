<?php

namespace App\Services;

use App\Core\Database;

/**
 * Builds a chronological transaction ledger for a loan -- the disbursed
 * principal, every charge (interest, NAMFISA levy, duty stamp, admin fee)
 * on its own line dated to the installment it belongs to, every penalty,
 * and every posted payment -- each with the running balance the borrower
 * still owes after that event. This is the actual "statement of account"
 * content; the existing invoice view only ever showed the planned
 * schedule, not what actually happened and when.
 *
 * Disbursement is booked as a single Debit for the principal only --
 * interest/levy/stamp/fees are charged separately, one set of lines per
 * loan_schedules row (dated to that installment's due_date), so a reader
 * can see exactly what was charged and when rather than one lump sum on
 * day one. Only non-zero charge types produce a line.
 *
 * There is deliberately no "Payment Missed" line anymore -- those were a
 * neutral factual record but carried no debit/credit amount, so they
 * cluttered the ledger with rows that moved no money. Whether an
 * installment is overdue is already visible from the amortization
 * schedule table above this one and from comparing due_date to today.
 */
class LoanStatementService
{
    public static function ledger(int $loanId): array
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(principal_due), 0) AS principal,
                    COALESCE(SUM(principal_due + interest_due + fees_due + namfisa_levy_due + duty_stamp_due), 0) AS total
             FROM loan_schedules WHERE loan_id = ?"
        );
        $stmt->execute([$loanId]);
        $totals = $stmt->fetch();
        $principalTotal = round((float) $totals['principal'], 2);
        $openingBalance = round((float) $totals['total'], 2);

        $disbursement = $db->prepare(
            "SELECT disbursement_date, amount, disbursement_method, reference_no
             FROM loan_disbursements WHERE loan_id = ? AND status = 'Disbursed' ORDER BY disbursement_date LIMIT 1"
        );
        $disbursement->execute([$loanId]);
        $disbursementRow = $disbursement->fetch();

        $charges = $db->prepare(
            "SELECT installment_no, due_date, interest_due, namfisa_levy_due, duty_stamp_due, fees_due
             FROM loan_schedules WHERE loan_id = ? ORDER BY installment_no"
        );
        $charges->execute([$loanId]);
        $chargeRows = $charges->fetchAll();

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

        if ($disbursementRow) {
            $events[] = [
                'date' => $disbursementRow['disbursement_date'],
                'type' => 'Disbursement',
                'description' => 'Loan disbursed (' . $disbursementRow['disbursement_method'] . ')'
                    . ($disbursementRow['reference_no'] ? ' - Ref ' . $disbursementRow['reference_no'] : ''),
                'debit' => $principalTotal,
                'credit' => 0.0,
            ];
        } else {
            // No disbursement record found (e.g. legacy/edge case) -- still
            // seed the ledger with the principal so the running total is
            // correct from the first real transaction.
            $events[] = [
                'date' => null,
                'type' => 'Opening Balance',
                'description' => 'Opening principal balance',
                'debit' => $principalTotal,
                'credit' => 0.0,
            ];
        }

        foreach ($chargeRows as $c) {
            $monthLabel = $c['due_date'] ? date('F', strtotime($c['due_date'])) : null;

            if ((float) $c['interest_due'] > 0.009) {
                $events[] = [
                    'date' => $c['due_date'],
                    'type' => 'Interest',
                    'description' => 'Interest charged' . ($monthLabel ? ' for ' . $monthLabel : '') . ' - Installment ' . $c['installment_no'],
                    'debit' => round((float) $c['interest_due'], 2),
                    'credit' => 0.0,
                ];
            }
            if ((float) $c['namfisa_levy_due'] > 0.009) {
                $events[] = [
                    'date' => $c['due_date'],
                    'type' => 'Fee',
                    'description' => 'NAMFISA Levy charged - Installment ' . $c['installment_no'],
                    'debit' => round((float) $c['namfisa_levy_due'], 2),
                    'credit' => 0.0,
                ];
            }
            if ((float) $c['duty_stamp_due'] > 0.009) {
                $events[] = [
                    'date' => $c['due_date'],
                    'type' => 'Fee',
                    'description' => 'Stamp Duty charged - Installment ' . $c['installment_no'],
                    'debit' => round((float) $c['duty_stamp_due'], 2),
                    'credit' => 0.0,
                ];
            }
            if ((float) $c['fees_due'] > 0.009) {
                $events[] = [
                    'date' => $c['due_date'],
                    'type' => 'Fee',
                    'description' => 'Admin Fee charged - Installment ' . $c['installment_no'],
                    'debit' => round((float) $c['fees_due'], 2),
                    'credit' => 0.0,
                ];
            }
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
            // On a tied date: disbursement first, then charges (a charge
            // must land on the balance before a same-day payment can be
            // read as paying it off), then penalties, then payments last.
            $rank = fn ($e) => match (true) {
                $e['type'] === 'Disbursement' || $e['type'] === 'Opening Balance' => 0,
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
            'opening_balance' => $openingBalance,
            'events' => $events,
            'closing_balance' => $runningBalance,
        ];
    }
}
