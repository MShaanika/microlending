<?php

namespace Tests\Unit;

use App\Support\SplitLanePlanner;
use PHPUnit\Framework\TestCase;

class SplitLanePlannerTest extends TestCase
{
    private function row(int $scheduleId, int $loanNo, string $date, array $amounts): array
    {
        return ['schedule_id' => $scheduleId, 'loan_installment_no' => $loanNo, 'date' => $date, 'amounts' => $amounts];
    }

    public function testUnsplitInstallmentsAreOneLaneCarryingAllOfThem(): void
    {
        $lanes = SplitLanePlanner::plan([
            $this->row(5, 2, '2026-11-17', [27.57]),
            $this->row(6, 3, '2026-12-17', [27.57]),
            $this->row(7, 4, '2027-01-17', [27.56]),
        ]);

        $this->assertCount(1, $lanes);
        $this->assertSame([1, 2, 3], array_column($lanes[0]['installments'], 'installment_no'));
        $this->assertSame([5, 6, 7], array_column($lanes[0]['installments'], 'schedule_id'));
        $this->assertSame('2026-11-17', $lanes[0]['first_date']);
        $this->assertSame(27.57, $lanes[0]['amount'], 'Loaded at the largest installment amount');
    }

    public function testThreeInstallmentsEachInTwoPartsAreTwoLanesNotSix(): void
    {
        $lanes = SplitLanePlanner::plan([
            $this->row(5, 2, '2026-11-17', [13.78, 13.79]),
            $this->row(6, 3, '2026-12-17', [13.78, 13.79]),
            $this->row(7, 4, '2027-01-17', [13.78, 13.79]),
        ]);

        $this->assertCount(2, $lanes);
        $this->assertSame([13.78, 13.78, 13.78], array_column($lanes[0]['installments'], 'amount'));
        $this->assertSame([13.79, 13.79, 13.79], array_column($lanes[1]['installments'], 'amount'));
        $this->assertSame(3, count($lanes[1]['installments']));
    }

    public function testUnevenSplitsGiveShorterLaterLanes(): void
    {
        $lanes = SplitLanePlanner::plan([
            $this->row(5, 2, '2026-11-17', [27.57]),
            $this->row(6, 3, '2026-12-17', [13.78, 13.79]),
            $this->row(7, 4, '2027-01-17', [9.19, 9.19, 9.19]),
        ]);

        $this->assertCount(3, $lanes);
        $this->assertCount(3, $lanes[0]['installments']);
        $this->assertCount(2, $lanes[1]['installments']);
        $this->assertCount(1, $lanes[2]['installments']);
        // Lane 2 skips installment #2 entirely, and its own sequence restarts at 1.
        $this->assertSame([6, 7], array_column($lanes[1]['installments'], 'schedule_id'));
        $this->assertSame([1, 2], array_column($lanes[1]['installments'], 'installment_no'));
        $this->assertSame('2026-12-17', $lanes[1]['first_date']);
        $this->assertSame('2027-01-17', $lanes[2]['first_date']);
    }

    public function testLaneAmountIsItsLargestInstallment(): void
    {
        $lanes = SplitLanePlanner::plan([
            $this->row(5, 2, '2026-11-17', [27.57]),
            $this->row(6, 3, '2026-12-17', [17.57]),
        ]);
        $this->assertSame(27.57, $lanes[0]['amount']);
    }

    public function testNoInstallmentsMeansNoLanes(): void
    {
        $this->assertSame([], SplitLanePlanner::plan([]));
    }

    public function testOnlyInstallmentsThatDifferNeedAnUpdate(): void
    {
        $planned = [
            1 => ['date' => '2026-11-17', 'amount' => 27.57],
            2 => ['date' => '2026-12-17', 'amount' => 17.57],
            3 => ['date' => '2027-01-17', 'amount' => 27.57],
        ];
        $actual = [
            1 => ['date' => '2026-11-17', 'amount' => 27.57],
            2 => ['date' => '2026-12-17', 'amount' => 27.57],
            3 => ['date' => '2027-01-18', 'amount' => 27.57],
        ];
        $this->assertSame([2, 3], SplitLanePlanner::installmentsNeedingUpdate($planned, $actual));
        $this->assertSame([], SplitLanePlanner::installmentsNeedingUpdate($planned, $planned));
    }

    public function testActualInstallmentsAreKeyedByCollexiaNumberAcrossFieldSpellings(): void
    {
        $actual = \App\Services\CollexiaLaneScheduleService::actualInstallments([
            ['intId' => 10, 'installmentNo' => 1, 'scheduledDate' => '20261117', 'installmentAmount' => 27.57, 'status' => 1],
            ['intId' => 11, 'noOfInstallment' => 2, 'scheduleDate' => '2026-12-17', 'installmentAmount' => 27.57, 'status' => 1],
            ['intId' => 12, 'installmentNo' => 3, 'scheduledDate' => '', 'installmentAmount' => 1, 'status' => 1],
        ]);
        $this->assertSame([1, 2], array_keys($actual), 'An installment without a usable date is skipped');
        $this->assertSame('2026-11-17', $actual[1]['date']);
        $this->assertSame('2026-12-17', $actual[2]['date']);
        $this->assertSame(11, $actual[2]['intId']);
    }
}
