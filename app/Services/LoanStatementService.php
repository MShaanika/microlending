<?php

namespace App\Services;

use App\Core\Database;

/**
 * Builds a chronological transaction ledger for a loan -- disbursement,
 * every posted payment, and every penalty charge, each with the running
 * balance the borrower still owes after that event. This is the actual
 * "statement of account" content; the existing invoice view only ever
 * showed the planned schedule, not what actually happened and when.
 *
 * The opening balance is the loan's original contractual amount (principal
 * + interest + fees + NAMFISA levy + duty stamp) taken from the schedule's
 * own due columns, which never change after creation -- only penalty_due
 * and the *_paid tracking columns move later, so summing those columns is
 * immune to penalties added after disbursement.
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
            "SELECT payment_no, payment_date, payment_source, reference_no, amount_received
             FROM payments WHERE loan_id = ? AND status = 'Posted' ORDER BY payment_date, id"
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
            $events[] = [
                'date' => $p['payment_date'],
                'type' => 'Payment',
                'description' => 'Payment received (' . $p['payment_source'] . ')'
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
