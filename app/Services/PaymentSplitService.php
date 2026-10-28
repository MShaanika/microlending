<?php

namespace App\Services;

/**
 * Splits ONE payment received on an original loan across the loan and its released top-ups. The money goes to whatever is due soonest -- by
 * due date, the original first when two parts are due on the same day -- exactly as a single loan's payment goes to its oldest unpaid
 * installment. Whatever is left after every part is fully paid is overpayment and stays with the original loan.
 *
 * Pure arithmetic in whole cents: the parts always add up to the amount received, to the cent.
 */
class PaymentSplitService
{
    /**
     * @param int[] $loanIds the original loan first, then its top-ups
     * @param array<int, array{loan_id: int|string, installment_no: int|string, due_date: string, owing: float|string}> $unpaidRows what is still owed, per schedule row
     * @return array<int, float> loan id => the part of the payment it receives (every loan id is present, 0 when it gets nothing)
     */
    public static function plan(array $loanIds, array $unpaidRows, float $amount): array
    {
        $loanIds = array_values(array_map('intval', $loanIds));
        $order = array_flip($loanIds);
        $cents = array_fill_keys($loanIds, 0);
        $remaining = (int) round($amount * 100);

        usort($unpaidRows, function ($a, $b) use ($order) {
            return strcmp((string) $a['due_date'], (string) $b['due_date'])
                ?: (($order[(int) $a['loan_id']] ?? 0) <=> ($order[(int) $b['loan_id']] ?? 0))
                ?: ((int) $a['installment_no'] <=> (int) $b['installment_no']);
        });

        foreach ($unpaidRows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $loanId = (int) $row['loan_id'];
            if (!isset($cents[$loanId])) {
                continue;
            }
            $owing = (int) round((float) $row['owing'] * 100);
            if ($owing <= 0) {
                continue;
            }
            $take = min($owing, $remaining);
            $cents[$loanId] += $take;
            $remaining -= $take;
        }

        if ($remaining > 0 && $loanIds !== []) {
            $cents[$loanIds[0]] += $remaining; // overpayment stays with the original loan
        }

        $out = [];
        foreach ($cents as $loanId => $c) {
            $out[$loanId] = round($c / 100, 2);
        }
        return $out;
    }
}
