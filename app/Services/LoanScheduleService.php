<?php

namespace App\Services;

/**
 * Builds a loan amortization schedule (the period-by-period breakdown of
 * principal, interest, fees and closing balance for the life of a loan).
 *
 * Supports the two interest methods used by loan_products.interest_method:
 *  - "Flat"            : interest calculated once on the loan basis
 *                         (principal + NAMFISA levy + duty stamp) and spread
 *                         evenly across the term.
 *  - "Reducing Balance" : interest calculated each period on the outstanding
 *                         balance (standard declining-balance amortization).
 *
 * NAMFISA levy (statutory, % of principal) and duty stamp (statutory, flat
 * per loan) are Namibian regulatory charges that the borrower repays but the
 * lender must remit to government. They are interest-bearing (included in
 * the basis interest accrues on) and folded evenly into each installment's
 * principal component, so every installment comes out equal -- the levy and
 * duty stamp are still raised as a single dated transaction for regulatory
 * reporting (StatutoryCharge::recordNamfisaLevy/recordDutyStamp, keyed off
 * the aggregate `namfisa_levy`/`duty_stamp` this service returns), but that
 * is independent of how the repayment is spread across the schedule.
 */
class LoanScheduleService
{
    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     interest_amount: float,
     *     admin_fee: float,
     *     namfisa_levy: float,
     *     duty_stamp: float,
     *     total_payable: float,
     *     installment_amount: float
     * }
     */
    public static function generate(
        float $principal,
        int $termMonths,
        float $annualInterestRate,
        float $adminFee,
        string $interestMethod,
        string $startDate,
        float $namfisaLevyRate = 0,
        float $dutyStampAmount = 0,
        ?int $paymentDay = null
    ): array {
        $termMonths = max(1, $termMonths);
        $namfisaLevy = round($principal * ($namfisaLevyRate / 100), 2);
        $dutyStamp = round($dutyStampAmount, 2);

        $result = match ($interestMethod) {
            'Reducing Balance' => self::reducingBalance($principal, $termMonths, $annualInterestRate, $adminFee, $startDate, $namfisaLevy, $dutyStamp, $paymentDay),
            default => self::flat($principal, $termMonths, $annualInterestRate, $adminFee, $startDate, $namfisaLevy, $dutyStamp, $paymentDay),
        };

        $result['namfisa_levy'] = $namfisaLevy;
        $result['duty_stamp'] = $dutyStamp;

        return $result;
    }

    /**
     * Computes the due date `$monthsAhead` months after `$anchor`, landing on
     * `$day` of that target month -- clamped to the month's actual length
     * (e.g. day 31 against a 30-day month lands on the 30th) instead of
     * PHP's DateTime::modify("+N month"), which overflows into the following
     * month when the day doesn't exist (2026-01-31 + 1 month -> 2026-03-03).
     */
    private static function nextDueDate(\DateTimeImmutable $anchor, int $monthsAhead, int $day): \DateTimeImmutable
    {
        $year = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n') + $monthsAhead;
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;
        $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, min($day, $daysInMonth)));
    }

    private static function flat(
        float $principal,
        int $termMonths,
        float $rate,
        float $adminFee,
        string $startDate,
        float $namfisaLevy,
        float $dutyStamp,
        ?int $paymentDay = null
    ): array {
        $interestBasis = round($principal + $namfisaLevy + $dutyStamp, 2);
        $totalInterest = round($interestBasis * ($rate / 100), 2);
        $totalPayable = round($interestBasis + $totalInterest + $adminFee, 2);

        $principalPerPeriod = round($interestBasis / $termMonths, 2);
        $interestPerPeriod = round($totalInterest / $termMonths, 2);
        $feePerPeriod = round($adminFee / $termMonths, 2);

        $rows = [];
        $balance = $interestBasis;
        $date = new \DateTimeImmutable($startDate);
        $anchorDay = $paymentDay ?? (int) $date->format('j');

        for ($period = 1; $period <= $termMonths; $period++) {
            $isLast = $period === $termMonths;
            $due = self::nextDueDate($date, $period, $anchorDay);

            $principalDue = $isLast ? round($balance, 2) : $principalPerPeriod;
            $interestDue = $isLast ? round($totalInterest - ($interestPerPeriod * ($termMonths - 1)), 2) : $interestPerPeriod;
            $feeDue = $isLast ? round($adminFee - ($feePerPeriod * ($termMonths - 1)), 2) : $feePerPeriod;

            $opening = round($balance, 2);
            $balance = round($balance - $principalDue, 2);

            $rows[] = [
                'installment_no' => $period,
                'due_date' => $due->format('Y-m-d'),
                'opening_balance' => $opening,
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'fees_due' => $feeDue,
                'namfisa_levy_due' => 0,
                'duty_stamp_due' => 0,
                'penalty_due' => 0,
                'total_due' => round($principalDue + $interestDue + $feeDue, 2),
                'closing_balance' => max($balance, 0),
            ];
        }

        $installment = round($totalPayable / $termMonths, 2);

        return [
            'rows' => $rows,
            'interest_amount' => $totalInterest,
            'admin_fee' => $adminFee,
            'total_payable' => $totalPayable,
            'installment_amount' => $installment,
        ];
    }

    private static function reducingBalance(
        float $principal,
        int $termMonths,
        float $annualRate,
        float $adminFee,
        string $startDate,
        float $namfisaLevy,
        float $dutyStamp,
        ?int $paymentDay = null
    ): array {
        $interestBasis = round($principal + $namfisaLevy + $dutyStamp, 2);
        $monthlyRate = ($annualRate / 100) / 12;

        if ($monthlyRate > 0) {
            $installment = $interestBasis * $monthlyRate / (1 - (1 + $monthlyRate) ** (-$termMonths));
        } else {
            $installment = $interestBasis / $termMonths;
        }
        $installment = round($installment, 2);

        $rows = [];
        $balance = $interestBasis;
        $totalInterest = 0.0;
        $date = new \DateTimeImmutable($startDate);
        $anchorDay = $paymentDay ?? (int) $date->format('j');
        $feePerPeriod = round($adminFee / $termMonths, 2);

        for ($period = 1; $period <= $termMonths; $period++) {
            $isLast = $period === $termMonths;
            $due = self::nextDueDate($date, $period, $anchorDay);

            $opening = round($balance, 2);
            $interestDue = round($opening * $monthlyRate, 2);
            $principalDue = $isLast ? $opening : round($installment - $interestDue, 2);

            $feeDue = $isLast ? round($adminFee - ($feePerPeriod * ($termMonths - 1)), 2) : $feePerPeriod;

            $balance = round($opening - $principalDue, 2);
            $totalInterest += $interestDue;

            $rows[] = [
                'installment_no' => $period,
                'due_date' => $due->format('Y-m-d'),
                'opening_balance' => $opening,
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'fees_due' => $feeDue,
                'namfisa_levy_due' => 0,
                'duty_stamp_due' => 0,
                'penalty_due' => 0,
                'total_due' => round($principalDue + $interestDue + $feeDue, 2),
                'closing_balance' => max($balance, 0),
            ];
        }

        $totalInterest = round($totalInterest, 2);
        $totalPayable = round($interestBasis + $totalInterest + $adminFee, 2);

        return [
            'rows' => $rows,
            'interest_amount' => $totalInterest,
            'admin_fee' => $adminFee,
            'total_payable' => $totalPayable,
            'installment_amount' => $installment,
        ];
    }
}
