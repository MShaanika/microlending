<?php

namespace App\Services;

/**
 * Deterministic keyword-based categorization of parsed bank statement
 * transactions (see BorrowerBankStatementCsvParser), implementing the
 * client's own literal keyword lists rather than an AI call -- a CSV is
 * already structured data, so this is cheaper, faster, and matches the
 * spec's wording exactly instead of relying on semantic judgment.
 *
 * Produces the same result shape AiBankStatementAnalyzer's AI path does, so
 * both pipelines can be persisted through one code path in the controller.
 */
class BankStatementKeywordCategorizer
{
    private const INCOME_KEYWORDS = ['salary', 'pay', 'deposit', 'payment from', 'fnb app', 'payment'];
    private const BANK_FEE_KEYWORDS = ['fee', 'service charge', 'monthly fee'];
    private const TRANSFER_KEYWORDS = ['send money', 'debit order', 'collection'];
    private const CASH_WITHDRAWAL_KEYWORDS = ['atm', 'cash withdrawal'];
    private const LIVING_EXPENSE_KEYWORDS = ['purchase', 'payment to', 'electricity', 'air time'];
    private const NSF_KEYWORDS = ['nsf', 'dishonour', 'dishonor', 'returned', 'insufficient funds'];

    /**
     * @param array $transactions Rows from BorrowerBankStatementCsvParser::parse()
     * @return array{
     *     months_covered: int,
     *     analysis_period_start: ?string,
     *     analysis_period_end: ?string,
     *     average_monthly_income: float,
     *     average_monthly_expenses: float,
     *     net_monthly_cash_flow: float,
     *     average_closing_balance: float,
     *     existing_commitments_total: float,
     *     existing_commitments: array,
     *     expense_breakdown: array,
     *     transactions: array,
     *     nsf_count: int,
     *     risk_flags: array,
     *     summary: string
     * }
     */
    public static function categorize(array $transactions): array
    {
        if (empty($transactions)) {
            return self::empty();
        }

        usort($transactions, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $months = [];
        $totalIncome = 0.0;
        $totalExpenses = 0.0;
        $nsfCount = 0;
        $expenseBreakdown = ['bank_fees' => 0.0, 'transfers' => 0.0, 'cash_withdrawals' => 0.0, 'living_expenses' => 0.0, 'other' => 0.0];
        $categorizedTransactions = [];
        $debitGroups = []; // "description|amount" => count, for recurring-payment detection
        $lastBalanceByMonth = [];

        foreach ($transactions as $t) {
            $monthKey = substr($t['date'], 0, 7);
            $months[$monthKey] = true;
            $desc = strtolower($t['description']);

            if (self::containsAny($desc, self::NSF_KEYWORDS)) {
                $nsfCount++;
            }

            if ($t['type'] === 'Credit') {
                $category = self::containsAny($desc, self::INCOME_KEYWORDS) ? 'Income' : 'Other';
                if ($category === 'Income') {
                    $totalIncome += $t['amount'];
                }
            } else {
                $category = self::expenseCategory($desc);
                $totalExpenses += $t['amount'];
                $expenseBreakdown[self::breakdownKey($category)] += $t['amount'];

                $groupKey = $desc . '|' . number_format($t['amount'], 2, '.', '');
                $debitGroups[$groupKey] = ($debitGroups[$groupKey] ?? ['count' => 0, 'description' => $t['description'], 'amount' => $t['amount']]);
                $debitGroups[$groupKey]['count']++;
            }

            if ($t['running_balance'] !== null) {
                $lastBalanceByMonth[$monthKey] = $t['running_balance'];
            }

            $categorizedTransactions[] = [
                'date' => $t['date'],
                'description' => $t['description'],
                'amount' => $t['amount'],
                'type' => $t['type'],
                'category' => $category,
                'running_balance' => $t['running_balance'],
            ];
        }

        $monthsCovered = max(1, count($months));
        $averageIncome = round($totalIncome / $monthsCovered, 2);
        $averageExpenses = round($totalExpenses / $monthsCovered, 2);

        // Recurring fixed-amount debits to the same description, appearing
        // 2+ times and not already an obvious fee/withdrawal -- the spec's
        // own definition of "Potential Loan Repayment".
        $existingCommitments = [];
        $existingCommitmentsTotal = 0.0;
        foreach ($debitGroups as $group) {
            $groupDesc = strtolower($group['description']);
            if ($group['count'] < 2) {
                continue;
            }
            if (self::containsAny($groupDesc, self::BANK_FEE_KEYWORDS) || self::containsAny($groupDesc, self::CASH_WITHDRAWAL_KEYWORDS)) {
                continue;
            }
            $existingCommitments[] = [
                'type' => 'Loan',
                'creditor_name' => $group['description'] ?: 'Unknown',
                'description' => 'Recurring debit detected ' . $group['count'] . ' times in the statement.',
                'monthly_amount' => $group['amount'],
            ];
            $existingCommitmentsTotal += $group['amount'];
        }

        $averageClosingBalance = !empty($lastBalanceByMonth) ? round(array_sum($lastBalanceByMonth) / count($lastBalanceByMonth), 2) : 0.0;

        $riskFlags = [];
        if ($totalExpenses > 0 && ($expenseBreakdown['bank_fees'] / $totalExpenses) > 0.05) {
            $riskFlags[] = 'High bank fees (' . round(($expenseBreakdown['bank_fees'] / $totalExpenses) * 100) . '% of total expenses)';
        }
        if (!empty($lastBalanceByMonth) && min($lastBalanceByMonth) < max(100, $averageIncome * 0.05)) {
            $riskFlags[] = 'Low balance detected during the statement period';
        }
        if ($nsfCount > 0) {
            $riskFlags[] = $nsfCount . ' potential NSF/dishonoured payment event(s) detected';
        }

        $dates = array_column($transactions, 'date');

        return [
            'months_covered' => $monthsCovered,
            'analysis_period_start' => min($dates),
            'analysis_period_end' => max($dates),
            'average_monthly_income' => $averageIncome,
            'average_monthly_expenses' => $averageExpenses,
            'net_monthly_cash_flow' => round($averageIncome - $averageExpenses, 2),
            'average_closing_balance' => $averageClosingBalance,
            'existing_commitments_total' => round($existingCommitmentsTotal, 2),
            'existing_commitments' => $existingCommitments,
            'expense_breakdown' => array_map(fn ($v) => round($v / $monthsCovered, 2), $expenseBreakdown),
            'transactions' => $categorizedTransactions,
            'nsf_count' => $nsfCount,
            'risk_flags' => $riskFlags,
            'summary' => "Analyzed {$monthsCovered} month(s) of activity (" . count($transactions) . ' transactions) using keyword-based categorization rules.',
        ];
    }

    private static function expenseCategory(string $lowerDescription): string
    {
        if (self::containsAny($lowerDescription, self::BANK_FEE_KEYWORDS)) {
            return 'Bank Fees';
        }
        if (self::containsAny($lowerDescription, self::TRANSFER_KEYWORDS)) {
            return 'Transfer';
        }
        if (self::containsAny($lowerDescription, self::CASH_WITHDRAWAL_KEYWORDS)) {
            return 'Cash Withdrawal';
        }
        if (self::containsAny($lowerDescription, self::LIVING_EXPENSE_KEYWORDS)) {
            return 'Living Expenses';
        }
        return 'Other';
    }

    private static function breakdownKey(string $category): string
    {
        return match ($category) {
            'Bank Fees' => 'bank_fees',
            'Transfer' => 'transfers',
            'Cash Withdrawal' => 'cash_withdrawals',
            'Living Expenses' => 'living_expenses',
            default => 'other',
        };
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function empty(): array
    {
        return [
            'months_covered' => 0,
            'analysis_period_start' => null,
            'analysis_period_end' => null,
            'average_monthly_income' => 0.0,
            'average_monthly_expenses' => 0.0,
            'net_monthly_cash_flow' => 0.0,
            'average_closing_balance' => 0.0,
            'existing_commitments_total' => 0.0,
            'existing_commitments' => [],
            'expense_breakdown' => ['bank_fees' => 0.0, 'transfers' => 0.0, 'cash_withdrawals' => 0.0, 'living_expenses' => 0.0, 'other' => 0.0],
            'transactions' => [],
            'nsf_count' => 0,
            'risk_flags' => [],
            'summary' => 'No transactions found in the uploaded file.',
        ];
    }
}
