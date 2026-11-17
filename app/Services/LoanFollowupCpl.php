<?php

namespace App\Services;

/**
 * Turns the answers to the loan questions into what the Creditinfo file needs: the paid-out loans with the payments the staff reported. A loan with an
 * instalment that fell due by the month end and has NO answer yet - or whose "not paid" answer is older than the re-ask time - is left out of the file and listed as
 * pending (never guessed as paid or unpaid).
 */
final class LoanFollowupCpl
{
    /**
     * @param array<int, array{followup: array<string,mixed>, application: array<string,mixed>, instalments: array<int,array<string,mixed>>, payments?: array<int, array<int, array{amount:string,paid_on:string}>>}> $entries
     * @param string $now the moment the file is built (a "not paid" answer whose re-ask time has passed is stale)
     * @return array{book: ApplicationLoanBook, pending: array<int, array{application_no:string, name:string, instalment_no:int, due_date:string}>}
     */
    public function build(array $entries, string $asOf, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $approved = [];
        $pending = [];
        foreach ($entries as $e) {
            $f = $e['followup'];
            if ($f['status'] !== 'Open' || $f['approved'] !== 'Yes' || $f['disbursed'] !== 'Yes' || empty($f['disbursed_on']) || $f['approved_amount'] === null || $f['pay_day'] === null) {
                continue;
            }
            if ($f['disbursed_on'] > $asOf) {
                continue; // belongs to a later month's file
            }
            $payments = [];
            $unanswered = null;
            foreach ($e['instalments'] as $i) {
                if ($i['due_date'] > $asOf) {
                    continue;
                }
                $unsettled = $i['paid_amount'] === null || (float) $i['paid_amount'] < (float) $i['expected_amount'] - 0.004;
                if (($i['answered_at'] === null && $i['paid_amount'] === null) || ($unsettled && !empty($i['next_ask_at']) && $i['next_ask_at'] <= $now)) {
                    $unanswered ??= $i;
                    continue;
                }
                foreach ($e['payments'][(int) $i['id']] ?? [] as $p) {
                    if ($p['paid_on'] <= $asOf) {
                        $payments[] = ['amount' => (string) $p['amount'], 'date' => (string) $p['paid_on']];
                    }
                }
            }
            $app = $e['application'];
            if ($unanswered !== null) {
                $pending[] = [
                    'application_no' => (string) $app['application_no'],
                    'name' => trim($app['applicant_first_name'] . ' ' . $app['applicant_last_name']),
                    'instalment_no' => (int) $unanswered['instalment_no'],
                    'due_date' => (string) $unanswered['due_date'],
                ];
                continue;
            }
            $approved[] = [
                'application' => $app,
                'amount' => (float) $f['approved_amount'],
                'paid_out' => (string) $f['disbursed_on'],
                'pay_day' => (int) $f['pay_day'],
                'months' => (int) ($f['term_months'] ?: ($app['requested_term_months'] ?? 1)),
                'payments' => $payments,
            ];
        }
        return ['book' => new ApplicationLoanBook($approved), 'pending' => $pending];
    }
}
