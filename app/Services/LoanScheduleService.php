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
 * lender must remit to government -- they are charged entirely in the first
 * installment (matching how they are raised as a single dated transaction)
 * and are interest-bearing, i.e. included in the basis interest accrues on.
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
        float $dutyStampAmount = 0
    ): array {
        $termMonths = max(1, $termMonths);
        $namfisaLevy = round($principal * ($namfisaLevyRate / 100), 2);
        $dutyStamp = round($dutyStampAmount, 2);

        $result = match ($interestMethod) {
            'Reducing Balance' => self::reducingBalance($principal, $termMonths, $annualInterestRate, $adminFee, $startDate, $namfisaLevy, $dutyStamp),
            default => self::flat($principal, $termMonths, $annualInterestRate, $adminFee, $startDate, $namfisaLevy, $dutyStamp),
        };

        $result['namfisa_levy'] = $namfisaLevy;
        $result['duty_stamp'] = $dutyStamp;

        return $result;
    }

    private static function flat(
        float $principal,
        int $termMonths,
        float $rate,
        float $adminFee,
        string $startDate,
        float $namfisaLevy,
        float $dutyStamp
    ): array {
        $interestBasis = round($principal + $namfisaLevy + $dutyStamp, 2);
        $totalInterest = round($interestBasis * ($rate / 100), 2);
        $totalPayable = round($interestBasis + $totalInterest + $adminFee, 2);

        $principalPerPeriod = round($principal / $termMonths, 2);
        $interestPerPeriod = round($totalInterest / $termMonths, 2);
        $feePerPeriod = round($adminFee / $termMonths, 2);

        $rows = [];
        $balance = $interestBasis;
        $date = new \DateTimeImmutable($startDate);

        for ($period = 1; $period <= $termMonths; $period++) {
            $isLast = $period === $termMonths;
            $due = $date->modify("+{$period} month");

            $principalDue = $isLast ? round($balance - ($period === 1 ? $namfisaLevy + $dutyStamp : 0), 2) : $principalPerPeriod;
            $interestDue = $isLast ? round($totalInterest - ($interestPerPeriod * ($termMonths - 1)), 2) : $interestPerPeriod;
            $feeDue = $isLast ? round($adminFee - ($feePerPeriod * ($termMonths - 1)), 2) : $feePerPeriod;
            $levyDue = $period === 1 ? $namfisaLevy : 0;
            $stampDue = $period === 1 ? $dutyStamp : 0;

            $opening = round($balance, 2);
            $balance = round($balance - $principalDue - $levyDue - $stampDue, 2);

            $rows[] = [
                'installment_no' => $period,
                'due_date' => $due->format('Y-m-d'),
                'opening_balance' => $opening,
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'fees_due' => $feeDue,
                'namfisa_levy_due' => $levyDue,
                'duty_stamp_due' => $stampDue,
                'penalty_due' => 0,
                'total_due' => round($principalDue + $interestDue + $feeDue + $levyDue + $stampDue, 2),
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
        float $dutyStamp
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
        $feePerPeriod = round($adminFee / $termMonths, 2);

        for ($period = 1; $period <= $termMonths; $period++) {
            $isLast = $period === $termMonths;
            $due = $date->modify("+{$period} month");

            $opening = round($balance, 2);
            $interestDue = round($opening * $monthlyRate, 2);
            $repayment = $isLast ? $opening : round($installment - $interestDue, 2);

            $levyDue = 0.0;
            $stampDue = 0.0;
            if ($period === 1) {
                $levyDue = min($namfisaLevy, max($repayment, 0));
                $repayment = round($repayment - $levyDue, 2);
                $stampDue = min($dutyStamp, max($repayment, 0));
                $repayment = round($repayment - $stampDue, 2);
            }
            $principalDue = round($repayment, 2);

            $feeDue = $isLast ? round($adminFee - ($feePerPeriod * ($termMonths - 1)), 2) : $feePerPeriod;

            $balance = round($opening - $principalDue - $levyDue - $stampDue, 2);
            $totalInterest += $interestDue;

            $rows[] = [
                'installment_no' => $period,
                'due_date' => $due->format('Y-m-d'),
                'opening_balance' => $opening,
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'fees_due' => $feeDue,
                'namfisa_levy_due' => $levyDue,
                'duty_stamp_due' => $stampDue,
                'penalty_due' => 0,
                'total_due' => round($principalDue + $interestDue + $feeDue + $levyDue + $stampDue, 2),
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
