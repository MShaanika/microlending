<?php

namespace Tests\Unit;

use App\Controllers\DebitOrderController;
use PHPUnit\Framework\TestCase;

/**
 * The warning shown when a loan has changed since its mandates were placed.
 * Pure logic (loan schedule rows + mandate carriers in, findings out).
 */
class DebitOrderScheduleDriftTest extends TestCase
{
    private function drift(array $schedule, array $carriers): array
    {
        $controller = (new \ReflectionClass(DebitOrderController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DebitOrderController::class, 'scheduleDrift');
        $method->setAccessible(true);
        return $method->invoke($controller, $schedule, $carriers);
    }

    private function row(int $id, int $no, float $due, float $paid, string $status = 'Pending'): array
    {
        return ['id' => $id, 'installment_no' => $no, 'total_due' => $due, 'total_paid' => $paid, 'status' => $status];
    }

    private function carrier(float $amount, string $date, string $status = 'Registered'): array
    {
        return ['amount' => $amount, 'planned_date' => $date, 'collexia_date' => null, 'status' => $status];
    }

    private function future(int $days = 30): string
    {
        return date('Y-m-d', strtotime("+$days days"));
    }

    public function testNothingIsReportedWhenTheLoanStillAgrees(): void
    {
        $this->assertSame([], $this->drift(
            [$this->row(1, 2, 27.57, 0)],
            [1 => [$this->carrier(13.78, $this->future()), $this->carrier(13.79, $this->future())]]
        ), 'Two split parts adding up to what is owed agree with the loan');
    }

    public function testAPartPaymentAfterPlacementIsReported(): void
    {
        $findings = $this->drift([$this->row(1, 2, 27.57, 10.00)], [1 => [$this->carrier(27.57, $this->future())]]);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Installment #2 now owes', $findings[0]);
    }

    public function testAnAlreadyPaidInstallmentThatIsStillScheduledIsReported(): void
    {
        $findings = $this->drift([$this->row(1, 3, 27.57, 27.57, 'Paid')], [1 => [$this->carrier(27.57, $this->future())]]);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('already paid', $findings[0]);
    }

    public function testAnInstallmentAlreadyProcessedByCollexiaIsNotCompared(): void
    {
        $this->assertSame([], $this->drift(
            [$this->row(1, 2, 27.57, 27.57, 'Paid')],
            [1 => [$this->carrier(27.57, date('Y-m-d', strtotime('-3 days')))]]
        ), 'A past collect-on date means it already collected, so being paid is expected');
    }

    public function testCancelledMandatesAreIgnored(): void
    {
        $this->assertSame([], $this->drift(
            [$this->row(1, 2, 27.57, 27.57, 'Paid')],
            [1 => [$this->carrier(27.57, $this->future(), 'Cancelled')]]
        ));
    }
}
