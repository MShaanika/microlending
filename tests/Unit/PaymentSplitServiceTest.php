<?php

namespace Tests\Unit;

use App\Services\PaymentSplitService;
use PHPUnit\Framework\TestCase;

/** One payment on an original loan with top-ups is split by what is due soonest -- pure arithmetic in whole cents. */
class PaymentSplitServiceTest extends TestCase
{
    private function row(int $loanId, int $no, string $due, float $owing): array
    {
        return ['loan_id' => $loanId, 'installment_no' => $no, 'due_date' => $due, 'owing' => $owing];
    }

    public function testTheMonthlyAmountIsSplitByWhatEachPartIsDue(): void
    {
        $rows = [$this->row(1, 2, '2026-12-30', 1000.0), $this->row(2, 1, '2026-12-30', 657.14), $this->row(1, 3, '2027-01-30', 1000.0), $this->row(2, 2, '2027-01-30', 657.14)];
        $this->assertSame([1 => 1000.0, 2 => 657.14], PaymentSplitService::plan([1, 2], $rows, 1657.14), 'exactly one month of both parts');
    }

    public function testTheMoneyGoesToTheEarliestDueDateFirstAndTheOriginalWinsATie(): void
    {
        $rows = [$this->row(2, 1, '2026-11-30', 500.0), $this->row(1, 1, '2026-12-30', 1000.0), $this->row(2, 2, '2026-12-30', 500.0)];
        $this->assertSame([1 => 0.0, 2 => 400.0], PaymentSplitService::plan([1, 2], $rows, 400.0), 'the top-up is due first, so it is paid first');
        $this->assertSame([1 => 600.0, 2 => 500.0], PaymentSplitService::plan([1, 2], $rows, 1100.0), 'then the original, which wins a same-day tie with the top-up');
    }

    public function testAPartPaymentStopsPartWayThroughARow(): void
    {
        $rows = [$this->row(1, 1, '2026-12-30', 1000.0), $this->row(2, 1, '2026-12-30', 657.14)];
        $this->assertSame([1 => 1000.0, 2 => 100.0], PaymentSplitService::plan([1, 2], $rows, 1100.0));
    }

    public function testWhatIsLeftOverStaysWithTheOriginalLoan(): void
    {
        $rows = [$this->row(1, 1, '2026-12-30', 100.0), $this->row(2, 1, '2026-12-30', 50.0)];
        $this->assertSame([1 => 350.0, 2 => 50.0], PaymentSplitService::plan([1, 2], $rows, 400.0), '250 is overpayment and goes to the original loan');
    }

    public function testThePartsAlwaysAddUpToTheAmountToTheCent(): void
    {
        $rows = [$this->row(1, 1, '2026-12-30', 333.33), $this->row(2, 1, '2026-12-30', 333.34), $this->row(3, 1, '2026-12-30', 333.33)];
        $plan = PaymentSplitService::plan([1, 2, 3], $rows, 777.77);
        $this->assertSame(777.77, round(array_sum($plan), 2));
        $this->assertSame([1 => 333.33, 2 => 333.34, 3 => 111.1], $plan);
    }

    public function testNothingOwingMeansTheWholeAmountIsOverpaymentOnTheOriginal(): void
    {
        $this->assertSame([1 => 250.0, 2 => 0.0], PaymentSplitService::plan([1, 2], [], 250.0));
    }
}
