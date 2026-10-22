<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A rescheduled loan's old instalments are closed to nothing due and nothing paid, with the stored status left as 'Paid' so every
 * "is it still owed?" test keeps working. They were not paid, so they are SHOWN as "Rescheduled" -- but an instalment that really
 * received a payment stays "Paid".
 */
class ScheduleStatusLabelTest extends TestCase
{
    public function testAnInstalmentClosedByARescheduleWithNothingPaidIsShownAsRescheduled(): void
    {
        $this->assertSame('Rescheduled', schedule_status_label(['status' => 'Paid', 'total_due' => '0.00', 'total_paid' => '0.00']));
        $this->assertSame('Rescheduled', schedule_status_label(['status' => 'Paid', 'total_due' => 0, 'total_paid' => 0]));
    }

    public function testAnInstalmentThatReallyWasPaidStaysPaid(): void
    {
        $this->assertSame('Paid', schedule_status_label(['status' => 'Paid', 'total_due' => '164.98', 'total_paid' => '164.98']));
    }

    public function testAPartPaymentThatWasThenClosedByARescheduleStaysPaid(): void
    {
        // 40.00 was received before the reschedule closed the row at what had been paid.
        $this->assertSame('Paid', schedule_status_label(['status' => 'Paid', 'total_due' => '40.00', 'total_paid' => '40.00']));
    }

    public function testOtherStatusesAreNeverRelabelled(): void
    {
        foreach (['Pending', 'Partial', 'In Arrears', 'Written Off'] as $status) {
            $this->assertSame($status, schedule_status_label(['status' => $status, 'total_due' => '0.00', 'total_paid' => '0.00']));
        }
    }

    public function testARowWithoutAmountsIsLeftAlone(): void
    {
        $this->assertSame('Paid', schedule_status_label(['status' => 'Paid']));
        $this->assertSame('', schedule_status_label([]));
    }
}
