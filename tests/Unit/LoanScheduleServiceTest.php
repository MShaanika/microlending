<?php

namespace Tests\Unit;

use App\Services\LoanScheduleService;
use PHPUnit\Framework\TestCase;

/**
 * Pins LoanScheduleService's due-date math, pure logic with no DB access
 * (PHPUnit on this dev machine can't reach it -- see CollexiaClientTest's
 * docblock for the same constraint/reasoning).
 */
class LoanScheduleServiceTest extends TestCase
{
    public function testWithoutFirstDueDateTheFirstInstallmentIsStillStartDatePlusOneMonth(): void
    {
        // Pins the exact behavior reported as a bug (Start Date 2026-10-20
        // produced a first due date of 2026-11-20) -- that's still correct
        // for every caller that never passes firstDueDate (Reschedule/
        // TopUp recalculation), so this must not change.
        $result = LoanScheduleService::generate(2000, 2, 18, 0, 'Flat', '2026-10-20');
        $this->assertSame('2026-11-20', $result['rows'][0]['due_date']);
        $this->assertSame('2026-12-20', $result['rows'][1]['due_date']);
    }

    public function testFirstDueDateTakesInstallmentOneDirectlyNotStartDatePlusOneMonth(): void
    {
        $result = LoanScheduleService::generate(2000, 2, 18, 0, 'Flat', '2026-10-20', 0, 0, null, '2026-10-20');
        $this->assertSame('2026-10-20', $result['rows'][0]['due_date'], 'Installment 1 must land exactly on the chosen first due date, not a month later.');
        $this->assertSame('2026-11-20', $result['rows'][1]['due_date'], 'Installment 2 must be one month after the FIRST DUE DATE, not after start_date.');
    }

    public function testFirstDueDateCanDifferFromStartDate(): void
    {
        // Admin picks a start date but wants a genuine grace period --
        // still fully supported, just explicit instead of forced.
        $result = LoanScheduleService::generate(2000, 3, 18, 0, 'Flat', '2026-10-01', 0, 0, null, '2026-11-15');
        $this->assertSame('2026-11-15', $result['rows'][0]['due_date']);
        $this->assertSame('2026-12-15', $result['rows'][1]['due_date']);
        $this->assertSame('2027-01-15', $result['rows'][2]['due_date']);
    }

    public function testPaymentDayOverrideStillAppliesWithFirstDueDate(): void
    {
        $result = LoanScheduleService::generate(2000, 2, 18, 0, 'Flat', '2026-10-20', 0, 0, 5, '2026-10-20');
        // Installment 1 is still exactly the chosen first due date (paymentDay
        // doesn't retroactively move it), but installment 2 lands on day 5.
        $this->assertSame('2026-10-20', $result['rows'][0]['due_date']);
        $this->assertSame('2026-11-05', $result['rows'][1]['due_date']);
    }

    public function testFirstDueDateDayOfMonthBecomesTheAnchorWhenNoPaymentDayGiven(): void
    {
        $result = LoanScheduleService::generate(2000, 2, 18, 0, 'Flat', '2026-10-01', 0, 0, null, '2026-10-31');
        $this->assertSame('2026-10-31', $result['rows'][0]['due_date']);
        // November has 30 days -- clamped, not overflowed into December.
        $this->assertSame('2026-11-30', $result['rows'][1]['due_date']);
    }

    public function testReducingBalanceMethodAlsoRespectsFirstDueDate(): void
    {
        $result = LoanScheduleService::generate(2000, 2, 18, 0, 'Reducing Balance', '2026-10-20', 0, 0, null, '2026-10-20');
        $this->assertSame('2026-10-20', $result['rows'][0]['due_date']);
        $this->assertSame('2026-11-20', $result['rows'][1]['due_date']);
    }

    public function testGenerateForFixedInterestAlsoRespectsFirstDueDate(): void
    {
        $result = LoanScheduleService::generateForFixedInterest(2000, 2, 100, 0, '2026-10-20', null, '2026-10-20');
        $this->assertSame('2026-10-20', $result['rows'][0]['due_date']);
        $this->assertSame('2026-11-20', $result['rows'][1]['due_date']);
    }

    public function testFirstDueDateNeverChangesPrincipalOrInterestAmounts(): void
    {
        // Due dates are purely presentational/scheduling -- this service has
        // no day-count interest accrual, so choosing a different first due
        // date must never change how much is owed, only when.
        $withoutOverride = LoanScheduleService::generate(2000, 2, 18, 0, 'Flat', '2026-10-20');
        $withOverride = LoanScheduleService::generate(2000, 2, 18, 0, 'Flat', '2026-10-20', 0, 0, null, '2026-10-20');

        $this->assertSame($withoutOverride['interest_amount'], $withOverride['interest_amount']);
        $this->assertSame($withoutOverride['total_payable'], $withOverride['total_payable']);
        $this->assertSame($withoutOverride['installment_amount'], $withOverride['installment_amount']);
        foreach ($withoutOverride['rows'] as $i => $row) {
            $this->assertSame($row['principal_due'], $withOverride['rows'][$i]['principal_due']);
            $this->assertSame($row['interest_due'], $withOverride['rows'][$i]['interest_due']);
            $this->assertSame($row['total_due'], $withOverride['rows'][$i]['total_due']);
        }
    }
}
