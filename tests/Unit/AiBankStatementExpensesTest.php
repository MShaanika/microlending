<?php

namespace Tests\Unit;

use App\Services\AiBankStatementAnalyzer;
use PHPUnit\Framework\TestCase;

/** An analysis with income and commitments but no expenses and no transactions is an incomplete answer, not a statement with no spending. */
class AiBankStatementExpensesTest extends TestCase
{
    private const ZERO = ['bank_fees' => 0, 'transfers' => 0, 'cash_withdrawals' => 0, 'living_expenses' => 0, 'other' => 0];

    public function testIncomeAndCommitmentsWithNoExpensesAndNoTransactionsIsMissingExpenses(): void
    {
        $data = ['average_monthly_income' => 23301.14, 'average_monthly_expenses' => 0, 'existing_commitments_total' => 18761.11, 'expense_breakdown' => self::ZERO, 'transactions' => []];
        $this->assertTrue(AiBankStatementAnalyzer::isMissingExpenses($data));
        $this->assertSame($data, AiBankStatementAnalyzer::repairExpensesFromTransactions($data), 'nothing to work out from');
    }

    public function testRealExpenseFiguresAreNeverTouchedOrFlagged(): void
    {
        $data = ['average_monthly_income' => 9000, 'average_monthly_expenses' => 5000, 'months_covered' => 3, 'expense_breakdown' => ['bank_fees' => 300, 'transfers' => 9000, 'cash_withdrawals' => 3000, 'living_expenses' => 2700, 'other' => 0], 'transactions' => []];
        $this->assertFalse(AiBankStatementAnalyzer::isMissingExpenses($data));
        $this->assertSame($data, AiBankStatementAnalyzer::repairExpensesFromTransactions($data));
    }

    public function testExpensesAreWorkedOutFromTheListedDebitsWhenTheTotalsAreZero(): void
    {
        $data = [
            'average_monthly_income' => 10000, 'average_monthly_expenses' => 0, 'months_covered' => 2, 'expense_breakdown' => self::ZERO,
            'transactions' => [
                ['type' => 'Credit', 'amount' => 10000, 'category' => 'Income'],
                ['type' => 'Debit', 'amount' => 100.50, 'category' => 'Bank Fees'],
                ['type' => 'Debit', 'amount' => 3000, 'category' => 'Transfer'],
                ['type' => 'Debit', 'amount' => 500, 'category' => 'Cash Withdrawal'],
                ['type' => 'Debit', 'amount' => 250, 'category' => 'Living Expenses'],
                ['type' => 'Debit', 'amount' => 49.50, 'category' => 'Something else'],
            ],
        ];
        $fixed = AiBankStatementAnalyzer::repairExpensesFromTransactions($data);
        $this->assertSame(['bank_fees' => 100.5, 'transfers' => 3000.0, 'cash_withdrawals' => 500.0, 'living_expenses' => 250.0, 'other' => 49.5], $fixed['expense_breakdown']);
        $this->assertSame(1950.0, $fixed['average_monthly_expenses'], '3900 of debits over 2 months');
        $this->assertFalse(AiBankStatementAnalyzer::isMissingExpenses($fixed));
    }

    public function testAStatementWithNoActivityAtAllIsNotCalledIncomplete(): void
    {
        $this->assertFalse(AiBankStatementAnalyzer::isMissingExpenses(['average_monthly_income' => 0, 'average_monthly_expenses' => 0, 'existing_commitments_total' => 0, 'expense_breakdown' => self::ZERO, 'transactions' => []]));
    }
}
