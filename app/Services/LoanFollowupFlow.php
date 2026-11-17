<?php

namespace App\Services;

/**
 * The questions staff are asked about an online application, one at a time, and what each answer does. Pure: no database, no clock (the caller passes "now"), so
 * every rule can be tested.
 *
 *   approved?            Yes -> amount approved -> paid out? -> (Yes) the pay-out date and the client's pay day -> the instalments are worked out -> "was instalment N paid?"
 *                        No  -> "are you planning to approve it?" No = closed; Yes / Not sure = asked again in two days
 *   paid out?            No  -> asked again in two days
 *   was instalment paid? asked from the day after it fell due; a "No" or "Partly" is asked again after a week, until it is paid in full
 */
final class LoanFollowupFlow
{
    public const RE_ASK_DAYS = 2;
    public const RE_ASK_UNPAID_DAYS = 7;
    /** After this many times asking without an answer that settles it, the owner is told. */
    public const ESCALATE_AFTER = 4;

    /**
     * The next thing to ask about this application, or null when nothing is due now.
     *
     * @param array<string,mixed> $f the loan_followups row
     * @param array<int, array<string,mixed>> $instalments its instalments
     * @return array{key:string, text:string, instalment_id?:int, attention:bool}|null
     */
    public function nextQuestion(array $f, array $instalments, string $now): ?array
    {
        if (($f['status'] ?? 'Open') !== 'Open') {
            return null;
        }
        $attention = (int) ($f['ask_count'] ?? 0) >= self::ESCALATE_AFTER;
        $waiting = !empty($f['next_ask_at']) && $f['next_ask_at'] > $now;

        if (($f['approved'] ?? null) === null) {
            return $waiting ? null : ['key' => 'approved', 'text' => 'Did you approve this loan?', 'attention' => $attention];
        }
        if ($f['approved'] === 'No') {
            if (($f['plans_to_approve'] ?? null) === null) {
                return ['key' => 'plans', 'text' => 'It was not approved. Are you planning to approve it?', 'attention' => $attention];
            }
            if ($f['plans_to_approve'] !== 'No' && !$waiting) {
                return ['key' => 'approved', 'text' => 'Last time this loan was not approved yet. Has it been approved since?', 'attention' => $attention];
            }
            return null;
        }
        if (($f['approved_amount'] ?? null) === null) {
            return ['key' => 'amount', 'text' => 'How much did you approve (N$)?', 'attention' => $attention];
        }
        if (($f['disbursed'] ?? null) === null || ($f['disbursed'] === 'No' && !$waiting)) {
            return ['key' => 'disbursed', 'text' => 'Was the money paid out to the client already?', 'attention' => $attention];
        }
        if ($f['disbursed'] === 'No') {
            return null;
        }
        if (empty($f['disbursed_on']) || ($f['pay_day'] ?? null) === null) {
            return ['key' => 'payout', 'text' => 'When was it paid out, and on which day of the month does the client get paid?', 'attention' => $attention];
        }
        foreach ($instalments as $i) {
            if ($i['due_date'] < substr($now, 0, 10) && !$this->settled($i) && (empty($i['next_ask_at']) || $i['next_ask_at'] <= $now)) {
                return ['key' => 'instalment', 'instalment_id' => (int) $i['id'], 'text' => 'Instalment ' . $i['instalment_no'] . ' (due ' . $i['due_date'] . ', N$ ' . number_format((float) $i['expected_amount'], 2, '.', ' ') . '): did the client pay?', 'attention' => false];
            }
        }
        return null;
    }

    /**
     * What an answer changes. Throws InvalidArgumentException (with a message for the person) when the answer cannot be used.
     *
     * @param array<string,mixed> $f
     * @param array<string,mixed> $input
     * @param array<string,mixed> $application the loan_applications row (for sanity checks)
     * @return array{update: array<string,mixed>, note: string, schedule: bool}
     */
    public function answer(array $f, string $question, array $input, array $application, string $now): array
    {
        $today = substr($now, 0, 10);
        $next = date('Y-m-d H:i:s', strtotime($now . ' +' . self::RE_ASK_DAYS . ' days'));
        $count = (int) ($f['ask_count'] ?? 0) + 1;

        switch ($question) {
            case 'approved':
                $a = $this->choice($input['answer'] ?? '', ['Yes', 'No', 'Not sure']);
                if ($a === 'Yes') {
                    return ['update' => ['approved' => 'Yes', 'ask_count' => 0, 'next_ask_at' => null], 'note' => 'Approved: Yes', 'schedule' => false];
                }
                if ($a === 'No') {
                    return ['update' => ['approved' => 'No', 'plans_to_approve' => null, 'ask_count' => 0, 'next_ask_at' => null], 'note' => 'Approved: No', 'schedule' => false];
                }
                return ['update' => ['ask_count' => $count, 'next_ask_at' => $next], 'note' => 'Approved: not sure (asked again after ' . $next . ')', 'schedule' => false];

            case 'plans':
                $a = $this->choice($input['answer'] ?? '', ['Yes', 'No', 'Not sure']);
                if ($a === 'No') {
                    return ['update' => ['plans_to_approve' => 'No', 'status' => 'Closed', 'closed_reason' => 'Not approved and not going to be', 'next_ask_at' => null], 'note' => 'Planning to approve: No (closed)', 'schedule' => false];
                }
                return ['update' => ['plans_to_approve' => $a, 'ask_count' => $count, 'next_ask_at' => $next], 'note' => 'Planning to approve: ' . $a . ' (asked again after ' . $next . ')', 'schedule' => false];

            case 'amount':
                $amount = $this->money($input['amount'] ?? '');
                $term = trim((string) ($input['term'] ?? '')) === '' ? (int) ($application['requested_term_months'] ?? 1) : (int) $input['term'];
                if ($term < 1 || $term > 12) {
                    throw new \InvalidArgumentException('The term must be from 1 to 12 months.');
                }
                return ['update' => ['approved_amount' => $amount, 'term_months' => $term], 'note' => 'Amount approved: N$ ' . number_format($amount, 2, '.', ' ') . ' over ' . $term . ' month(s)', 'schedule' => false];

            case 'disbursed':
                $a = $this->choice($input['answer'] ?? '', ['Yes', 'No']);
                if ($a === 'No') {
                    return ['update' => ['disbursed' => 'No', 'ask_count' => $count, 'next_ask_at' => $next], 'note' => 'Paid out: No (asked again after ' . $next . ')', 'schedule' => false];
                }
                return ['update' => ['disbursed' => 'Yes', 'ask_count' => 0, 'next_ask_at' => null], 'note' => 'Paid out: Yes', 'schedule' => false];

            case 'payout':
                $date = $this->date($input['paid_out'] ?? '');
                $appliedOn = substr((string) ($application['created_at'] ?? ''), 0, 10);
                if ($date > $today) {
                    throw new \InvalidArgumentException('The pay-out date cannot be in the future.');
                }
                if ($appliedOn !== '' && $date < $appliedOn) {
                    throw new \InvalidArgumentException('The money cannot be paid out before the application was made (' . $appliedOn . ').');
                }
                $payDay = (int) ($input['pay_day'] ?? 0);
                if ($payDay < 1 || $payDay > 31) {
                    throw new \InvalidArgumentException('The pay day must be a day of the month, from 1 to 31.');
                }
                return ['update' => ['disbursed_on' => $date, 'pay_day' => $payDay], 'note' => 'Paid out on ' . $date . '; client is paid on day ' . $payDay, 'schedule' => true];
        }
        throw new \InvalidArgumentException('Unknown question.');
    }

    /**
     * What an instalment answer changes. "amount" is what the client paid THIS time (each payment is kept with its own date); the instalment's total is the sum.
     *
     * @param array<string,mixed> $instalment
     * @param array<string,mixed> $input
     * @return array{update: array<string,mixed>, note: string, payment: ?array{amount:float, paid_on:string}}
     */
    public function answerInstalment(array $instalment, array $input, string $now, string $paidOutOn = ''): array
    {
        $today = substr($now, 0, 10);
        $a = $this->choice($input['answer'] ?? '', ['Yes', 'Partly', 'No']);
        $expected = (float) $instalment['expected_amount'];
        $retry = date('Y-m-d H:i:s', strtotime($now . ' +' . self::RE_ASK_UNPAID_DAYS . ' days'));

        if ($a === 'No') {
            return ['update' => ['paid_amount' => (float) ($instalment['paid_amount'] ?? 0), 'paid_on' => $instalment['paid_on'] ?? null, 'next_ask_at' => $retry], 'note' => 'Instalment ' . $instalment['instalment_no'] . ': not paid (asked again after ' . $retry . ')', 'payment' => null];
        }
        $before = (float) ($instalment['paid_amount'] ?? 0);
        $remaining = round($expected - $before, 2);
        $now_paid = $a === 'Yes' && trim((string) ($input['amount'] ?? '')) === '' ? $remaining : $this->money($input['amount'] ?? '');
        $total = round($before + $now_paid, 2);
        $on = $this->date($input['paid_on'] ?? '');
        if ($paidOutOn !== '' && $on < $paidOutOn) {
            throw new \InvalidArgumentException('The payment cannot be dated before the money was paid out (' . $paidOutOn . ').');
        }
        if ($on > $today) {
            throw new \InvalidArgumentException('The payment date cannot be in the future.');
        }
        if ($on < date('Y-m-d', strtotime($instalment['due_date'] . ' -60 days'))) {
            throw new \InvalidArgumentException('That payment date is far before the instalment fell due: check the date.');
        }
        if ($a === 'Partly' && $total >= $expected) {
            throw new \InvalidArgumentException('Together with what was paid before (N$ ' . number_format($before, 2, '.', ' ') . ') this reaches the instalment of N$ ' . number_format($expected, 2, '.', ' ') . '. Choose Yes if it is now paid in full.');
        }
        if ($a === 'Yes' && $total < $expected - 0.004) {
            throw new \InvalidArgumentException('Paid in full needs N$ ' . number_format($remaining, 2, '.', ' ') . ' more. Choose Partly for a part payment.');
        }
        $paidInFull = $total >= $expected - 0.004;
        return [
            'update' => ['paid_amount' => $total, 'paid_on' => $on, 'next_ask_at' => $paidInFull ? null : $retry],
            'note' => 'Instalment ' . $instalment['instalment_no'] . ': received N$ ' . number_format($now_paid, 2, '.', ' ') . ' on ' . $on . ($paidInFull ? ' - paid in full' : ' - N$ ' . number_format($total, 2, '.', ' ') . ' paid so far'),
            'payment' => ['amount' => $now_paid, 'paid_on' => $on],
        ];
    }

    /**
     * The instalments of a loan, priced exactly as LegacyCplMapper prices it (fee, levy, 30% interest), each due on the client's pay day.
     *
     * @return array<int, array{instalment_no:int, due_date:string, expected_amount:float}>
     */
    public function schedule(float $amount, int $months, string $paidOut, int $payDay): array
    {
        $months = max(1, $months);
        $initial = $amount + LegacyCplMapper::FEE + round($amount * LegacyCplMapper::LEVY_RATE, 2);
        $final = round($initial * (1 + ApplicationLoanBook::INTEREST_PERCENTAGE / 100), 2);
        $instalment = (float) ceil($final / $months);
        $first = ApplicationLoanBook::firstInstalmentDate($paidOut, $payDay);

        $out = [];
        for ($n = 1; $n <= $months; $n++) {
            $due = date('Y-m-d', (int) strtotime(substr($first, 0, 8) . '01 +' . ($n - 1) . ' month'));
            $out[] = ['instalment_no' => $n, 'due_date' => substr($due, 0, 8) . substr($first, 8, 2), 'expected_amount' => $instalment];
        }
        return $out;
    }

    /** Settled = paid in full. */
    public function settled(array $instalment): bool
    {
        return $instalment['paid_amount'] !== null && (float) $instalment['paid_amount'] >= (float) $instalment['expected_amount'] - 0.004;
    }

    /** The day an instalment answer is due by: the 3rd of the month after the due month. */
    public function answerByDate(string $dueDate): string
    {
        return date('Y-m-03', (int) strtotime(substr($dueDate, 0, 8) . '01 +1 month'));
    }

    private function choice(mixed $value, array $allowed): string
    {
        $v = trim((string) $value);
        foreach ($allowed as $a) {
            if (strcasecmp($a, $v) === 0) {
                return $a;
            }
        }
        throw new \InvalidArgumentException('Please choose one of: ' . implode(', ', $allowed) . '.');
    }

    private function money(mixed $value): float
    {
        $t = preg_replace('/(?i)n\$|nad|\s/', '', trim((string) $value)) ?? '';
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $t)) {
            $t = str_replace(',', '', $t);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $t) || (float) $t <= 0 || (float) $t > 500000) {
            throw new \InvalidArgumentException('Type the amount as digits only, like 4500 or 4500.50 (a dot for cents, never a comma).');
        }
        return (float) $t;
    }

    private function date(mixed $value): string
    {
        $v = trim((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 2000) {
            throw new \InvalidArgumentException('Choose a real date.');
        }
        return $v;
    }
}
