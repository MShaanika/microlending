<?php

namespace App\Services;

use App\Models\DebitOrder;
use App\Models\DebitOrderSplitLegInstallment;
use App\Models\DebitOrderSplitLegInstallmentPart;
use App\Models\Loan;

/**
 * What a loan's live Collexia mandate is still going to collect, set against what the loan actually owes -- so that a manual
 * payment made while a mandate is placed is noticed instead of being collected a second time.
 *
 * Two shapes of mandate behave differently, and the check treats them that way:
 *
 *  - A split "lane" mandate carries named installments, each aimed at one loan installment on its own date. If that loan installment
 *    is already paid, the collection becomes a refundable overpayment, and the collection can be stopped for exactly that installment.
 *  - A single recurring mandate collects the same amount on every date and Payment::recordAndAllocate() just applies each collection to
 *    whatever is next unpaid. So a manual payment doesn't hit one particular collection -- it makes the mandate collect MORE than the loan
 *    owes. The check compares what is still scheduled with what is outstanding and reports the surplus; the surplus collections are the
 *    last ones, which is what a stop cancels.
 *
 * A collection due today or tomorrow is treated as no longer stoppable (IMMINENT_DAYS): it is about to be, or already has been, sent
 * to the bank. Read-only apart from reading the database; it never calls Collexia.
 */
class MandateCollectionCheck
{
    /** A collection this many days away or fewer can no longer be stopped. */
    public const IMMINENT_DAYS = 1;

    private DebitOrder $debitOrders;
    private DebitOrderSplitLegInstallment $lane;
    private Loan $loans;
    private DebitOrderSplitLegInstallmentPart $parts;

    public function __construct(?DebitOrder $debitOrders = null, ?DebitOrderSplitLegInstallment $lane = null, ?Loan $loans = null, ?DebitOrderSplitLegInstallmentPart $parts = null)
    {
        $this->parts = $parts ?? new DebitOrderSplitLegInstallmentPart();
        $this->debitOrders = $debitOrders ?? new DebitOrder();
        $this->lane = $lane ?? new DebitOrderSplitLegInstallment();
        $this->loans = $loans ?? new Loan();
    }

    /**
     * @return array{
     *   mandate: ?array,
     *   kind: ?string,
     *   upcoming: array<int, array>,
     *   paidButScheduled: array<int, array>,
     *   surplus: ?array{count: int, amount: float, dates: string[], stoppable: int, per: float},
     *   canStop: bool
     * }
     */
    public function forLoan(int $loanId, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $empty = ['mandate' => null, 'kind' => null, 'upcoming' => [], 'paidButScheduled' => [], 'surplus' => null, 'canStop' => false];

        $mandate = $this->debitOrders->liveMandateForLoan($loanId);
        if (!$mandate) {
            // A released top-up is collected by its original loan's (combined) debit order: look there.
            $self = $this->loans->find($loanId);
            if ($self && !empty($self['topup_of_loan_id'])) {
                $loanId = (int) $self['topup_of_loan_id'];
                $mandate = $this->debitOrders->liveMandateForLoan($loanId);
            }
        }
        if (!$mandate) {
            return $empty;
        }
        $schedule = $this->loans->schedule($loanId);
        $owesById = [];
        foreach ($schedule as $row) {
            $owesById[(int) $row['id']] = ['owes' => round((float) $row['total_due'] - (float) $row['total_paid'], 2), 'row' => $row];
        }
        // A combined debit order also settles the schedule rows of the loan's top-ups.
        foreach ($this->loans->tranchesOf($loanId) as $tranche) {
            if ((int) $tranche['id'] === $loanId) {
                continue;
            }
            foreach ($this->loans->schedule((int) $tranche['id']) as $row) {
                $owesById[(int) $row['id']] = ['owes' => round((float) $row['total_due'] - (float) $row['total_paid'], 2), 'row' => $row];
            }
        }
        $result = $empty;
        $result['mandate'] = $mandate;
        // Only a mandate actually placed through the API can be stopped from here; an older batch-file one has to be stopped with Collexia.
        $result['canStop'] = !empty($mandate['collexia_api_contract_reference']) || (int) ($mandate['split_enabled'] ?? 0) === 1;

        if ((int) ($mandate['split_enabled'] ?? 0) === 1) {
            $result['kind'] = 'lane';
            $this->fillLane($result, $mandate, $owesById, $today);
        } else {
            $result['kind'] = 'single';
            $this->fillSingle($result, $mandate, $schedule, $owesById, $today);
        }

        usort($result['upcoming'], fn ($a, $b) => strcmp($a['collects_on'], $b['collects_on']));
        usort($result['paidButScheduled'], fn ($a, $b) => strcmp($a['collects_on'], $b['collects_on']));
        return $result;
    }

    /** True when a collection on this date can no longer be stopped. */
    public static function isImminent(string $collectsOn, ?string $today = null): bool
    {
        $today = $today ?? date('Y-m-d');
        return $collectsOn <= date('Y-m-d', strtotime($today . ' +' . self::IMMINENT_DAYS . ' day'));
    }

    private function fillLane(array &$result, array $mandate, array $owesById, string $today): void
    {
        foreach ($this->lane->carriersBySchedule((int) $mandate['id']) as $scheduleId => $carriers) {
            $info = $owesById[(int) $scheduleId] ?? null;
            if (!$info) {
                continue;
            }
            foreach ($carriers as $c) {
                $date = $c['collexia_date'] ?: $c['planned_date'];
                if (!in_array($c['status'], ['Registered', 'Load Pending'], true) || !$date || $date < $today || !empty($c['cancelled_at'])) {
                    continue;
                }
                // A COMBINED instalment (original loan + top-ups, one debit) is only paid when EVERY row behind it is paid.
                $cinfo = $this->combinedInfo($c, $owesById) ?? $info;
                $entry = [
                    'schedule_id' => (int) $scheduleId,
                    'installment_no' => (int) $cinfo['row']['installment_no'],
                    'collects_on' => $date,
                    'amount' => round((float) $c['amount'], 2),
                    'mandate' => (int) $c['split_no'],
                    'owes' => $cinfo['owes'],
                    // The schedule's own "Paid" status counts: it settles a row with a cent of rounding left over.
                    'paid' => $cinfo['row']['status'] === 'Paid' || $cinfo['owes'] < 0.005,
                    'imminent' => self::isImminent($date, $today),
                ];
                $result['upcoming'][] = $entry;
                if ($entry['paid']) {
                    $result['paidButScheduled'][] = $entry;
                }
            }
        }
    }

    /** What a combined lane instalment still owes across all its schedule rows, or null for an ordinary instalment. */
    private function combinedInfo(array $carrier, array $owesById): ?array
    {
        if (empty($carrier['lane_installment_id'])) {
            return null;
        }
        $parts = $this->parts->forInstallment((int) $carrier['lane_installment_id']);
        if (count($parts) < 2) {
            return null;
        }
        $owes = 0.0;
        $primary = null;
        $allPaid = true;
        foreach ($parts as $part) {
            $one = $owesById[(int) $part['schedule_id']] ?? null;
            if (!$one) {
                continue;
            }
            $primary ??= $one['row'];
            $owes += max(0.0, $one['owes']);
            $allPaid = $allPaid && ($one['row']['status'] === 'Paid' || $one['owes'] < 0.005);
        }
        if ($primary === null) {
            return null;
        }
        // 'Paid' status of the synthetic row = every part paid, so the existing paid test below holds
        $primary['status'] = $allPaid ? 'Paid' : ($primary['status'] === 'Paid' ? 'Pending' : $primary['status']);
        return ['owes' => $allPaid ? 0.0 : round($owes, 2), 'row' => $primary];
    }

    private function fillSingle(array &$result, array $mandate, array $schedule, array $owesById, string $today): void
    {
        $per = round((float) $mandate['debit_amount'], 2);
        if ($per <= 0 || !$schedule) {
            return;
        }
        $lastDue = max(array_column($schedule, 'due_date'));
        $dates = self::recurringDates((string) $mandate['start_date'], (int) $mandate['debit_day'], date('Y-m-d', strtotime($lastDue . ' +5 day')));
        $future = array_values(array_filter($dates, fn ($d) => $d >= $today));
        $outstanding = round(array_sum(array_map(fn ($o) => max(0.0, $o['owes']), $owesById)), 2);

        foreach (array_slice($future, 0, 6) as $d) {
            $result['upcoming'][] = [
                'schedule_id' => null, 'installment_no' => null, 'collects_on' => $d, 'amount' => $per, 'mandate' => null,
                'owes' => null, 'paid' => false, 'imminent' => self::isImminent($d, $today),
            ];
        }

        $surplusCount = (int) floor((count($future) * $per - $outstanding + 0.01) / $per);
        if ($surplusCount > 0 && $future) {
            $surplusCount = min($surplusCount, count($future));
            $last = array_slice($future, -$surplusCount);
            $stoppable = count(array_filter($last, fn ($d) => !self::isImminent($d, $today)));
            $result['surplus'] = [
                'count' => $surplusCount,
                'amount' => round($surplusCount * $per, 2),
                'dates' => $last,
                'stoppable' => $stoppable,
                'per' => $per,
            ];
        }
    }

    /**
     * The dates a recurring monthly mandate collects on: the start date, then the same day of each following month (capped to the
     * month's length), up to and including $until.
     *
     * @return string[] Y-m-d, ascending
     */
    public static function recurringDates(string $startDate, int $debitDay, string $until): array
    {
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$start) {
            return [];
        }
        $dates = [$start->format('Y-m-d')];
        $debitDay = max(1, min(31, $debitDay ?: (int) $start->format('j')));
        for ($k = 1; $k <= 120; $k++) {
            $first = $start->modify('first day of this month')->modify("+$k month");
            $day = min($debitDay, (int) $first->format('t'));
            $date = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
            if ($date > $until) {
                break;
            }
            $dates[] = $date;
        }
        return $dates;
    }
}
