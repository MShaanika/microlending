<?php

namespace App\Support;

/**
 * Turns "these installments, each divided into N parts" into split LANES --
 * one Collexia mandate per part position, each carrying every installment
 * that has that part (noOfInstallments = how many it carries).
 *
 * Three installments each split in two gives 2 lanes of 3 installments, not
 * 6 one-off mandates. Installments don't have to be split the same way: if
 * installment 3 has two parts and installment 4 has three, lane 1 and lane 2
 * carry both installments and lane 3 carries only installment 4.
 *
 * Pure function, no database access -- see SplitLanePlannerTest.
 */
class SplitLanePlanner
{
    /**
     * @param array<int, array{schedule_id: int, loan_installment_no: int, date: string, amounts: float[]}> $installments
     *        oldest installment first; amounts = the amount of each part, in part order
     * @return array<int, array{lane: int, amount: float, first_date: string, installments: array<int, array{installment_no: int, schedule_id: int, loan_installment_no: int, date: string, amount: float}>}>
     */
    public static function plan(array $installments): array
    {
        $maxParts = 0;
        foreach ($installments as $row) {
            $maxParts = max($maxParts, count($row['amounts']));
        }

        $lanes = [];
        for ($part = 1; $part <= $maxParts; $part++) {
            $carried = [];
            $sequence = 0;
            foreach ($installments as $row) {
                if (!isset($row['amounts'][$part - 1])) {
                    continue;
                }
                $sequence++;
                $carried[] = [
                    'installment_no' => $sequence,
                    'schedule_id' => (int) $row['schedule_id'],
                    'loan_installment_no' => (int) $row['loan_installment_no'],
                    'date' => $row['date'],
                    'amount' => round((float) $row['amounts'][$part - 1], 2),
                ];
            }
            if (!$carried) {
                continue;
            }
            $lanes[] = [
                'lane' => count($lanes) + 1,
                // The mandate is loaded at its largest installment so a later
                // per-installment amount update never has to go UP.
                'amount' => max(array_column($carried, 'amount')),
                'first_date' => $carried[0]['date'],
                'installments' => $carried,
            ];
        }

        return $lanes;
    }

    /**
     * Which of a mandate's installments need a Collexia update to match the
     * plan: any whose scheduled date or amount differs from what Collexia
     * currently holds. Both lists are keyed by Collexia's installment number.
     *
     * @param array<int, array{date: string, amount: float}> $planned
     * @param array<int, array{date: string, amount: float}> $actual
     * @return int[] installment numbers to update
     */
    public static function installmentsNeedingUpdate(array $planned, array $actual): array
    {
        $needs = [];
        foreach ($planned as $no => $plan) {
            if (!isset($actual[$no])) {
                continue;
            }
            if ($actual[$no]['date'] !== $plan['date'] || abs($actual[$no]['amount'] - $plan['amount']) > 0.004) {
                $needs[] = (int) $no;
            }
        }
        sort($needs);
        return $needs;
    }
}
