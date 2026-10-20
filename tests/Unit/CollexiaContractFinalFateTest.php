<?php

namespace Tests\Unit;

use App\Services\CollexiaMandateStatusSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Collexia's contract final-fate rule (Donna, 2026-10-21): a contract is Completed only when ALL
 * its installments are in a final status (2 Cancelled, 3 Insufficient Funds, 6 Completed,
 * 8 Rejected, 10 Disputed); anything in flight (1 Active, 5 In Tracking, 14, 15, 16) keeps it Active.
 */
class CollexiaContractFinalFateTest extends TestCase
{
    private function mandate(array $statuses, ?int $declared = null): array
    {
        return [
            'noOfInstallments' => $declared ?? count($statuses),
            'installments' => array_map(fn ($s) => ['status' => $s], $statuses),
        ];
    }

    public function testUseCase1OneInstallmentWithAFinalResultCompletesTheContract(): void
    {
        foreach ([2, 3, 6, 8, 10] as $final) {
            $this->assertSame('Completed', CollexiaMandateStatusSyncService::contractFinalFate($this->mandate([$final])), "status $final");
        }
    }

    public function testUseCase2AnInstallmentStillInFlightKeepsTheContractActive(): void
    {
        $this->assertSame('Active', CollexiaMandateStatusSyncService::contractFinalFate($this->mandate([6, 5])));
        foreach ([1, 5, 14, 15, 16] as $inFlight) {
            $this->assertSame('Active', CollexiaMandateStatusSyncService::contractFinalFate($this->mandate([6, $inFlight])), "status $inFlight");
        }
    }

    public function testEveryInstallmentFinalCompletesAMultiInstallmentContract(): void
    {
        $this->assertSame('Completed', CollexiaMandateStatusSyncService::contractFinalFate($this->mandate([6, 3, 8, 2, 10])));
    }

    public function testAnUnknownStatusIsTreatedAsStillInFlight(): void
    {
        $this->assertSame('Active', CollexiaMandateStatusSyncService::contractFinalFate($this->mandate([6, 99])));
    }

    public function testNoInstallmentsOrAPartialListNeverCompletesAContract(): void
    {
        $this->assertNull(CollexiaMandateStatusSyncService::contractFinalFate(['noOfInstallments' => 2]));
        $this->assertNull(CollexiaMandateStatusSyncService::contractFinalFate($this->mandate([6], 2)), 'only 1 of 2 installments listed');
    }

    public function testAnActiveContractWithAllInstallmentsFinalIsPromotedButCancelledIsNot(): void
    {
        $service = (new \ReflectionClass(CollexiaMandateStatusSyncService::class))->newInstanceWithoutConstructor();

        $active = ['mandate' => [array_merge($this->mandate([6, 3]), ['status' => 2])]];
        $this->assertSame('Completed', $service->mapMandateStatus($active));

        $inFlight = ['mandate' => [array_merge($this->mandate([6, 5]), ['status' => 2])]];
        $this->assertSame('Active', $service->mapMandateStatus($inFlight));

        $cancelled = ['mandate' => [array_merge($this->mandate([2, 2]), ['status' => 4])]];
        $this->assertSame('Cancelled', $service->mapMandateStatus($cancelled), 'a cancelled mandate stays Cancelled');

        $collexiaCompleted = ['mandate' => [array_merge($this->mandate([5]), ['status' => 3])]];
        $this->assertSame('Completed', $service->mapMandateStatus($collexiaCompleted), "Collexia's own Completed is taken as reported");
    }
}
