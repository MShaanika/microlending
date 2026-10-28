<?php

namespace Tests\Unit;

use App\Services\CombinedLoanService;
use PHPUnit\Framework\TestCase;

/** The original loan and its top-ups as one amortization schedule and one statement -- pure arithmetic, no database. */
class CombinedLoanServiceTest extends TestCase
{
    private function row(int $no, string $due, float $opening, float $totalDue, float $paid, float $closing, string $status = 'Pending'): array
    {
        return [
            'installment_no' => $no, 'due_date' => $due, 'opening_balance' => $opening, 'closing_balance' => $closing, 'status' => $status,
            'principal_due' => $totalDue - 200, 'interest_due' => 200.0, 'fees_due' => 0.0, 'namfisa_levy_due' => 0.0, 'duty_stamp_due' => 0.0, 'penalty_due' => 0.0,
            'total_due' => $totalDue, 'principal_paid' => 0.0, 'interest_paid' => 0.0, 'fees_paid' => 0.0, 'namfisa_levy_paid' => 0.0, 'duty_stamp_paid' => 0.0, 'penalty_paid' => 0.0, 'total_paid' => $paid,
        ];
    }

    private function tranches(): array
    {
        return [
            ['id' => 1, 'loan_no' => 'LN-A', 'start_date' => '2026-10-30', 'maturity_date' => '2027-02-28', 'principal_amount' => 3000, 'interest_amount' => 900, 'admin_fee' => 0, 'total_payable' => 3900, 'installment_amount' => 1000, 'schedule_due' => 3000, 'schedule_paid' => 1000],
            ['id' => 2, 'loan_no' => 'LN-B', 'start_date' => '2026-12-15', 'maturity_date' => '2027-03-30', 'principal_amount' => 1000, 'interest_amount' => 300, 'admin_fee' => 0, 'total_payable' => 1300, 'installment_amount' => 1000, 'schedule_due' => 2000, 'schedule_paid' => 0],
        ];
    }

    public function testRowsOnTheSameDueDateBecomeOneRow(): void
    {
        $a = [$this->row(1, '2026-11-30', 3000, 1000, 1000, 2000, 'Paid'), $this->row(2, '2026-12-30', 2000, 1000, 0, 1000), $this->row(3, '2027-01-30', 1000, 1000, 0, 0)];
        $b = [$this->row(1, '2026-12-30', 1000, 1000, 0, 500), $this->row(2, '2027-01-30', 500, 1000, 0, 0)];
        $rows = CombinedLoanService::mergeSchedule($this->tranches(), [1 => $a, 2 => $b]);

        $this->assertSame(['2026-11-30', '2026-12-30', '2027-01-30'], array_column($rows, 'due_date'));
        $this->assertSame([1, 2, 3], array_column($rows, 'installment_no'), 'one running number for the combined schedule');
        $this->assertSame([1000.0, 2000.0, 2000.0], array_column($rows, 'total_due'), 'amounts on the same date are added');
        $this->assertSame(['LN-A', 'LN-B'], array_column($rows[1]['parts'], 'loan_no'), 'the parts stay visible');
        $this->assertSame('Paid', $rows[0]['status']);
    }

    public function testTheBalanceIsTheCombinedBalanceAndATopUpCountsFromItsStart(): void
    {
        $a = [$this->row(1, '2026-11-30', 3000, 1000, 1000, 2000, 'Paid'), $this->row(2, '2026-12-30', 2000, 1000, 0, 1000)];
        $b = [$this->row(1, '2026-12-30', 1000, 1000, 0, 0)];
        $rows = CombinedLoanService::mergeSchedule($this->tranches(), [1 => $a, 2 => $b]);

        $this->assertSame(3000.0, $rows[0]['opening_balance'], 'before the top-up exists only the original counts');
        $this->assertSame(2000.0, $rows[0]['closing_balance']);
        $this->assertSame(3000.0, $rows[1]['opening_balance'], '2,000 left on the original + the 1,000 top-up, which started 15 Dec');
        $this->assertSame(1000.0, $rows[1]['closing_balance']);
    }

    public function testAnUnpaidOverdueDateIsInArrearsAndAPartPaymentIsPartial(): void
    {
        $a = [$this->row(1, '2020-01-30', 1000, 1000, 0, 0)];
        $b = [$this->row(1, '2099-01-30', 1000, 1000, 400, 0)];
        $rows = CombinedLoanService::mergeSchedule($this->tranches(), [1 => $a, 2 => $b]);
        $this->assertSame('In Arrears', $rows[0]['status']);
        $this->assertSame('Partial', $rows[1]['status']);
    }

    public function testClosedOutRowsOfARescheduledPartAreLeftOut(): void
    {
        $a = [$this->row(1, '2026-11-30', 1000, 0, 0, 0, 'Paid'), $this->row(2, '2026-12-30', 1000, 1000, 0, 0)];
        $rows = CombinedLoanService::mergeSchedule($this->tranches(), [1 => $a]);
        $this->assertSame(['2026-12-30'], array_column($rows, 'due_date'));
    }

    public function testTheLoanRowCarriesTheCombinedFigures(): void
    {
        $loan = CombinedLoanService::combinedLoanRow(['id' => 1, 'maturity_date' => '2027-02-28', 'loan_no' => 'LN-A', 'principal_amount' => 3000], $this->tranches());
        $this->assertSame(4000.0, $loan['principal_amount']);
        $this->assertSame(1200.0, $loan['interest_amount']);
        $this->assertSame(5200.0, $loan['total_payable']);
        $this->assertSame(2000.0, $loan['installment_amount']);
        $this->assertSame('2027-03-30', $loan['maturity_date'], 'the latest maturity of any part');
        $this->assertSame('LN-A', $loan['loan_no'], 'the original keeps its number');
    }

    public function testOneStatementWithOneRunningBalance(): void
    {
        $ledgers = [
            1 => ['opening_balance' => 3000.0, 'events' => [
                ['date' => '2026-10-30', 'type' => 'Opening', 'description' => 'Loan disbursed', 'debit' => 3000.0, 'credit' => 0.0, 'balance' => 3000.0],
                ['date' => '2026-11-30', 'type' => 'Payment', 'description' => 'Payment', 'debit' => 0.0, 'credit' => 1000.0, 'balance' => 2000.0],
            ], 'closing_balance' => 2000.0],
            2 => ['opening_balance' => 1000.0, 'events' => [
                ['date' => '2026-12-15', 'type' => 'Opening', 'description' => 'Top-up disbursed', 'debit' => 1000.0, 'credit' => 0.0, 'balance' => 1000.0],
            ], 'closing_balance' => 1000.0],
        ];
        $merged = CombinedLoanService::mergeLedgers($this->tranches(), $ledgers);
        $this->assertSame(4000.0, $merged['opening_balance']);
        $this->assertSame([3000.0, 2000.0, 3000.0], array_column($merged['events'], 'balance'));
        $this->assertSame(3000.0, $merged['closing_balance']);
        $this->assertStringStartsWith('[LN-B]', $merged['events'][2]['description']);
    }
}
