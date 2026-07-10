<?php

namespace App\Services;

/**
 * Builds a period-by-period depreciation schedule for tangible fixed assets,
 * or an amortization schedule for intangible assets (same math, different
 * label - both reduce an asset's book value over its useful life).
 */
class DepreciationService
{
    /**
     * @return array<int, array<string, mixed>> one row per month of useful life
     */
    public static function generate(
        float $capitalizedCost,
        float $residualValue,
        int $usefulLifeMonths,
        string $method,
        ?float $reducingBalanceRate,
        string $startDate
    ): array {
        $usefulLifeMonths = max(1, $usefulLifeMonths);
        $residualValue = max(0, min($residualValue, $capitalizedCost));

        if ($method === 'No Depreciation') {
            return [];
        }

        return $method === 'Reducing Balance'
            ? self::reducingBalance($capitalizedCost, $residualValue, $usefulLifeMonths, (float) $reducingBalanceRate, $startDate)
            : self::straightLine($capitalizedCost, $residualValue, $usefulLifeMonths, $startDate);
    }

    private static function straightLine(
        float $cost,
        float $residual,
        int $months,
        string $startDate
    ): array {
        $depreciableAmount = round($cost - $residual, 2);
        $perPeriod = round($depreciableAmount / $months, 2);

        $rows = [];
        $balance = $cost;
        $date = new \DateTimeImmutable($startDate);

        for ($period = 1; $period <= $months; $period++) {
            $isLast = $period === $months;
            $periodDate = $date->modify("+{$period} month");

            $opening = round($balance, 2);
            $amount = $isLast ? round($depreciableAmount - ($perPeriod * ($months - 1)), 2) : $perPeriod;
            $balance = round($opening - $amount, 2);

            $rows[] = [
                'period_no' => $period,
                'period_date' => $periodDate->format('Y-m-d'),
                'opening_book_value' => $opening,
                'depreciation_amount' => $amount,
                'closing_book_value' => max($balance, $residual),
            ];
        }

        return $rows;
    }

    private static function reducingBalance(
        float $cost,
        float $residual,
        int $months,
        float $annualRate,
        string $startDate
    ): array {
        $monthlyRate = ($annualRate / 100) / 12;
        $rows = [];
        $balance = $cost;
        $date = new \DateTimeImmutable($startDate);

        for ($period = 1; $period <= $months; $period++) {
            $isLast = $period === $months;
            $periodDate = $date->modify("+{$period} month");

            $opening = round($balance, 2);
            $amount = round($opening * $monthlyRate, 2);

            // Never depreciate below the residual value.
            $maxAllowed = round($opening - $residual, 2);
            if ($amount > $maxAllowed) {
                $amount = max($maxAllowed, 0);
            }
            if ($isLast) {
                $amount = max($maxAllowed, 0);
            }

            $balance = round($opening - $amount, 2);

            $rows[] = [
                'period_no' => $period,
                'period_date' => $periodDate->format('Y-m-d'),
                'opening_book_value' => $opening,
                'depreciation_amount' => $amount,
                'closing_book_value' => max($balance, $residual),
            ];
        }

        return $rows;
    }
}
