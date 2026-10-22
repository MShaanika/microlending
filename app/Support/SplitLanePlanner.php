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
     * @param array<int, array{schedule_id: int, loan_installment_no: int, date: string, amounts: float[], auto_cancel?: bool}> $installments
     *        oldest installment first; amounts = the amount of each part, in part order. auto_cancel marks an installment that was already
     *        paid by hand: it rides in the mandate (so the schedule keeps its numbering) and is cancelled at Collexia once the mandate is
     *        confirmed -- see CollexiaPaidInstallmentCanceller. The flag is only carried through; the planner never acts on it.
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
                    'auto_cancel' => !empty($row['auto_cancel']),
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
     * Everything in ONE mandate: every installment of the loan, with each part
     * of a split installment as its own installment of that same mandate on
     * the same collection date. Three installments with the middle one split
     * in two is one mandate of four installments. Same output shape as plan()
     * (a list with a single lane) so callers store and place it identically.
     *
     * Whether Collexia accepts two installments of one mandate on the same
     * date is its call; when it refuses, the caller falls back to plan()
     * (one mandate per part) -- see DebitOrderCollexiaController.
     *
     * @param array<int, array{schedule_id: int, loan_installment_no: int, date: string, amounts: float[]}> $installments
     */
    public static function planSingle(array $installments): array
    {
        $entries = [];
        foreach ($installments as $row) {
            foreach ($row['amounts'] as $amount) {
                $entries[] = [
                    'installment_no' => count($entries) + 1,
                    'schedule_id' => (int) $row['schedule_id'],
                    'loan_installment_no' => (int) $row['loan_installment_no'],
                    'date' => $row['date'],
                    'amount' => round((float) $amount, 2),
                    'auto_cancel' => !empty($row['auto_cancel']),
                ];
            }
        }
        if (!$entries) {
            return [];
        }

        return [[
            'lane' => 1,
            'amount' => max(array_column($entries, 'amount')),
            'first_date' => $entries[0]['date'],
            'installments' => $entries,
        ]];
    }

    /**
     * Merges two or more PARTS of one loan installment inside a lane that has not been sent to Collexia yet: the parts become a single
     * installment of their combined amount. The first selected part is kept (it holds the position and the date), the others are dropped,
     * and the lane's own installment numbers are closed up 1..n. The mandate's loaded amount stays the largest single installment, which is
     * the convention plan() uses so a later per-installment update never has to go up.
     *
     * Pure: no database. The caller applies the returned plan.
     *
     * @param array<int, array{id: int, installment_no: int, schedule_id: int, planned_date: string, planned_amount: mixed}> $rows the lane's rows, in installment order
     * @param int[] $mergeIds ids of the rows to merge
     * @return array{error: ?string, rows: array<int, array{id: int, installment_no: int, schedule_id: int, planned_date: string, planned_amount: float}>, deleted: int[], amount: float}
     */
    public static function mergeParts(array $rows, array $mergeIds): array
    {
        $fail = static fn (string $why) => ['error' => $why, 'rows' => [], 'deleted' => [], 'amount' => 0.0];

        $mergeIds = array_values(array_unique(array_map('intval', $mergeIds)));
        if (count($mergeIds) < 2) {
            return $fail('Select at least two parts to merge.');
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $selected = [];
        foreach ($mergeIds as $id) {
            if (!isset($byId[$id])) {
                return $fail('One of the selected parts is not part of this mandate.');
            }
            $selected[] = $byId[$id];
        }

        if (count(array_unique(array_map(static fn ($r) => (int) $r['schedule_id'], $selected))) !== 1) {
            return $fail('Only parts of the same loan installment can be merged.');
        }

        usort($selected, static fn ($a, $b) => (int) $a['installment_no'] <=> (int) $b['installment_no']);
        $keepId = (int) $selected[0]['id'];
        $total = round(array_sum(array_map(static fn ($r) => (float) $r['planned_amount'], $selected)), 2);
        $deleted = array_values(array_diff($mergeIds, [$keepId]));
        sort($deleted);

        $out = [];
        $no = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (in_array($id, $deleted, true)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'installment_no' => ++$no,
                'schedule_id' => (int) $row['schedule_id'],
                'planned_date' => (string) $row['planned_date'],
                'planned_amount' => $id === $keepId ? $total : round((float) $row['planned_amount'], 2),
            ];
        }

        return ['error' => null, 'rows' => $out, 'deleted' => $deleted, 'amount' => max(array_column($out, 'planned_amount'))];
    }

    /**
     * The inverse: the plan() input rebuilt from a stored mandate's installment
     * rows (debit_order_split_leg_installments), so a single mandate Collexia
     * refused can be re-planned as one mandate per part without asking staff
     * to re-enter anything.
     *
     * @param array<int, array{schedule_id: mixed, loan_installment_no: mixed, planned_date: string, planned_amount: mixed}> $laneRows in Collexia installment order
     */
    public static function rowsFromLaneRows(array $laneRows): array
    {
        $rows = [];
        foreach ($laneRows as $row) {
            $key = (int) $row['schedule_id'];
            if (!isset($rows[$key])) {
                $rows[$key] = ['schedule_id' => $key, 'loan_installment_no' => (int) $row['loan_installment_no'], 'date' => $row['planned_date'], 'amounts' => [], 'auto_cancel' => !empty($row['auto_cancel'])];
            }
            $rows[$key]['amounts'][] = round((float) $row['planned_amount'], 2);
        }
        return array_values($rows);
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
            // Collexia does not process on a Sunday and moves such an installment to the
            // Monday itself -- that is not a mismatch worth fighting every run.
            $acceptable = [$plan['date']];
            if ((int) date('N', strtotime($plan['date'])) === 7) {
                $acceptable[] = date('Y-m-d', strtotime($plan['date'] . ' +1 day'));
            }
            if (!in_array($actual[$no]['date'], $acceptable, true) || abs($actual[$no]['amount'] - $plan['amount']) > 0.004) {
                $needs[] = (int) $no;
            }
        }
        sort($needs);
        return $needs;
    }
}
