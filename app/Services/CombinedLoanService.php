<?php

namespace App\Services;

use App\Models\Loan;

/**
 * An original loan and its released top-ups, presented as ONE loan: one set of totals, one amortization schedule (a single row per due date)
 * and one statement of account. Nothing is stored or posted -- every figure is added up from the tranches' own schedules and ledgers, so each
 * tranche keeps its own journals, interest recognition and payments exactly as before.
 */
class CombinedLoanService
{
    /** Every numeric column of loan_schedules that adds up across tranches on the same due date. */
    private const SUMMED = [
        'principal_due', 'interest_due', 'fees_due', 'namfisa_levy_due', 'duty_stamp_due', 'penalty_due', 'total_due',
        'principal_paid', 'interest_paid', 'fees_paid', 'namfisa_levy_paid', 'duty_stamp_paid', 'penalty_paid', 'total_paid',
    ];

    /**
     * @param array $loan the loan as Loan::find() returns it
     * @return ?array{loan: array, tranches: array, schedule: array, ledger: array} null when the loan has no released top-ups
     */
    public static function forLoan(array $loan, ?Loan $loans = null): ?array
    {
        $loans ??= new Loan();
        $tranches = $loans->tranchesOf((int) $loan['id']);
        if ($tranches === []) {
            return null;
        }

        $rowsByLoan = [];
        $ledgers = [];
        foreach ($tranches as $t) {
            $rowsByLoan[(int) $t['id']] = $loans->schedule((int) $t['id']);
            $ledgers[(int) $t['id']] = LoanStatementService::ledger((int) $t['id']);
        }

        return [
            'loan' => self::combinedLoanRow($loan, $tranches),
            'tranches' => $tranches,
            'schedule' => self::mergeSchedule($tranches, $rowsByLoan),
            'ledger' => self::mergeLedgers($tranches, $ledgers),
        ];
    }

    /** The loan row with its money columns replaced by the combined figures (a monthly instalment only counts the parts still being paid). */
    public static function combinedLoanRow(array $loan, array $tranches): array
    {
        $sum = ['principal_amount' => 0.0, 'interest_amount' => 0.0, 'admin_fee' => 0.0, 'total_payable' => 0.0, 'installment_amount' => 0.0];
        $maturity = (string) ($loan['maturity_date'] ?? '');
        foreach ($tranches as $t) {
            foreach (['principal_amount', 'interest_amount', 'admin_fee', 'total_payable'] as $k) {
                $sum[$k] += (float) $t[$k];
            }
            if (round((float) $t['schedule_due'] - (float) $t['schedule_paid'], 2) > 0) {
                $sum['installment_amount'] += (float) $t['installment_amount'];
            }
            if (strcmp((string) $t['maturity_date'], $maturity) > 0) {
                $maturity = (string) $t['maturity_date'];
            }
        }
        foreach ($sum as $k => $v) {
            $sum[$k] = round($v, 2);
        }
        return $sum + ['maturity_date' => $maturity] + $loan;
    }

    /**
     * One row per due date. Money columns are added up; the balance columns follow the combined balance (each tranche counts from its start date,
     * at its own closing balance after its latest instalment). Closed-out rows of a rescheduled tranche (nothing due, nothing paid) are left out.
     *
     * @param array $tranches Loan::tranchesOf()
     * @param array<int, array> $rowsByLoan loan id => that loan's schedule rows
     */
    public static function mergeSchedule(array $tranches, array $rowsByLoan): array
    {
        $startOf = [];
        foreach ($tranches as $t) {
            $startOf[(int) $t['id']] = (string) $t['start_date'];
        }

        $byDate = [];
        foreach ($rowsByLoan as $loanId => $rows) {
            foreach ($rows as $row) {
                if (round((float) $row['total_due'], 2) <= 0.0 && round((float) $row['total_paid'], 2) <= 0.0) {
                    continue;
                }
                $byDate[(string) $row['due_date']][(int) $loanId][] = $row;
            }
        }
        ksort($byDate);

        // a tranche's rows in date order, to find its balance before/at any date
        $ordered = [];
        foreach ($rowsByLoan as $loanId => $rows) {
            $ordered[(int) $loanId] = array_values(array_filter($rows, fn ($r) => round((float) $r['total_due'], 2) > 0.0 || round((float) $r['total_paid'], 2) > 0.0));
            usort($ordered[(int) $loanId], fn ($a, $b) => strcmp((string) $a['due_date'], (string) $b['due_date']) ?: ((int) $a['installment_no'] <=> (int) $b['installment_no']));
        }
        $balanceBefore = function (int $loanId, string $date, bool $inclusive) use ($ordered, $startOf): float {
            $rows = $ordered[$loanId] ?? [];
            if ($rows === []) {
                return 0.0;
            }
            $last = null;
            foreach ($rows as $r) {
                $d = strcmp((string) $r['due_date'], $date);
                if ($d < 0 || ($inclusive && $d === 0)) {
                    $last = $r;
                }
            }
            if ($last !== null) {
                return (float) $last['closing_balance'];
            }
            return strcmp($startOf[$loanId] ?? '9999-12-31', $date) <= 0 ? (float) $rows[0]['opening_balance'] : 0.0;
        };

        $out = [];
        $no = 0;
        $today = date('Y-m-d');
        foreach ($byDate as $date => $perLoan) {
            $merged = ['installment_no' => ++$no, 'due_date' => $date, 'parts' => []];
            foreach (self::SUMMED as $k) {
                $merged[$k] = 0.0;
            }
            $statuses = [];
            foreach ($perLoan as $loanId => $rows) {
                foreach ($rows as $row) {
                    foreach (self::SUMMED as $k) {
                        $merged[$k] += (float) ($row[$k] ?? 0);
                    }
                    $statuses[] = schedule_status_label($row);
                    $merged['parts'][] = ['loan_id' => (int) $loanId, 'loan_no' => self::loanNo($tranches, (int) $loanId), 'installment_no' => (int) $row['installment_no'], 'total_due' => round((float) $row['total_due'], 2), 'total_paid' => round((float) $row['total_paid'], 2)];
                }
            }
            $opening = $closing = 0.0;
            foreach (array_keys($rowsByLoan) as $loanId) {
                $opening += $balanceBefore((int) $loanId, $date, false);
                $closing += $balanceBefore((int) $loanId, $date, true);
            }
            foreach (self::SUMMED as $k) {
                $merged[$k] = round($merged[$k], 2);
            }
            $merged['opening_balance'] = round($opening, 2);
            $merged['closing_balance'] = round($closing, 2);
            $merged['status'] = self::combinedStatus($statuses, $date, $merged, $today);
            $out[] = $merged;
        }
        return $out;
    }

    /** One date is only Paid when every part is; an overdue unpaid part makes it In Arrears; otherwise Partial / Pending. */
    private static function combinedStatus(array $statuses, string $date, array $merged, string $today): string
    {
        if (in_array('Written Off', $statuses, true)) {
            return 'Written Off';
        }
        $allPaid = $statuses !== [] && count(array_filter($statuses, fn ($s) => $s === 'Paid')) === count($statuses);
        if ($allPaid) {
            return 'Paid';
        }
        if (in_array('In Arrears', $statuses, true) || ($date <= $today && $merged['total_due'] > $merged['total_paid'])) {
            return 'In Arrears';
        }
        if (in_array('Partial', $statuses, true) || $merged['total_paid'] > 0) {
            return 'Partial';
        }
        return 'Pending';
    }

    private static function loanNo(array $tranches, int $loanId): string
    {
        foreach ($tranches as $t) {
            if ((int) $t['id'] === $loanId) {
                return (string) $t['loan_no'];
            }
        }
        return (string) $loanId;
    }

    /**
     * One statement: every tranche's events in date order with the loan number in front, and a single running balance.
     *
     * @param array<int, array> $ledgers loan id => LoanStatementService::ledger()
     */
    public static function mergeLedgers(array $tranches, array $ledgers): array
    {
        $events = [];
        $opening = 0.0;
        foreach ($ledgers as $loanId => $ledger) {
            $opening += (float) $ledger['opening_balance'];
            foreach ($ledger['events'] as $event) {
                $event['description'] = '[' . self::loanNo($tranches, (int) $loanId) . '] ' . $event['description'];
                $events[] = $event;
            }
        }
        usort($events, function ($a, $b) {
            $dateCompare = strcmp((string) $a['date'], (string) $b['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            $rank = fn ($e) => match (true) {
                $e['type'] === 'Opening' => 0,
                $e['type'] === 'Interest' || $e['type'] === 'Fee' => 1,
                $e['type'] === 'Penalty' => 2,
                default => 3,
            };
            return $rank($a) <=> $rank($b);
        });
        $running = 0.0;
        foreach ($events as &$event) {
            $running = round($running + $event['debit'] - $event['credit'], 2);
            $event['balance'] = $running;
        }
        unset($event);

        return ['opening_balance' => round($opening, 2), 'events' => $events, 'closing_balance' => $running];
    }
}
