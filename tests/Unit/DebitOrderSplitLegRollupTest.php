<?php

namespace Tests\Unit;

use App\Models\DebitOrderSplitLeg;
use PHPUnit\Framework\TestCase;

/**
 * Pins DebitOrderSplitLeg::rollupFromStatuses() -- the rule behind the
 * single collexia_api_status badge shown for a split debit order. The key
 * regression this guards: a genuinely mixed set of leg statuses (e.g. one
 * Registered, one Cancelled) must surface as 'Mixed', never silently
 * collapse to 'Registered' the way an earlier version of this logic did --
 * that hid a leg that had actually stopped collecting.
 */
class DebitOrderSplitLegRollupTest extends TestCase
{
    public function testNoLegsIsNotPlaced(): void
    {
        $this->assertSame('Not Placed', DebitOrderSplitLeg::rollupFromStatuses([]));
    }

    public function testAnyLoadFailedWinsEvenAmongOtherwiseHealthyLegs(): void
    {
        $this->assertSame('Load Failed', DebitOrderSplitLeg::rollupFromStatuses(['Registered', 'Load Failed']));
    }

    public function testAnyLoadPendingWinsOverRegistered(): void
    {
        $this->assertSame('Load Pending', DebitOrderSplitLeg::rollupFromStatuses(['Registered', 'Load Pending']));
    }

    public function testLoadFailedOutranksLoadPending(): void
    {
        $this->assertSame('Load Failed', DebitOrderSplitLeg::rollupFromStatuses(['Load Pending', 'Load Failed']));
    }

    public function testUnanimousRegisteredStaysRegistered(): void
    {
        $this->assertSame('Registered', DebitOrderSplitLeg::rollupFromStatuses(['Registered', 'Registered']));
    }

    public function testUnanimousCompletedStaysCompleted(): void
    {
        $this->assertSame('Completed', DebitOrderSplitLeg::rollupFromStatuses(['Completed', 'Completed']));
    }

    public function testUnanimousCancelledStaysCancelled(): void
    {
        $this->assertSame('Cancelled', DebitOrderSplitLeg::rollupFromStatuses(['Cancelled', 'Cancelled']));
    }

    public function testRegisteredAndCancelledIsMixedNotRegistered(): void
    {
        // The exact bug this replaces: one leg actually stopped collecting
        // (Cancelled) while the other is still live (Registered) -- must
        // never be reported as the falsely-reassuring 'Registered'.
        $this->assertSame('Mixed', DebitOrderSplitLeg::rollupFromStatuses(['Registered', 'Cancelled']));
    }

    public function testRegisteredAndCompletedIsMixed(): void
    {
        $this->assertSame('Mixed', DebitOrderSplitLeg::rollupFromStatuses(['Registered', 'Completed']));
    }

    public function testCancelledAndCompletedIsMixed(): void
    {
        $this->assertSame('Mixed', DebitOrderSplitLeg::rollupFromStatuses(['Cancelled', 'Completed']));
    }

    public function testThreeWaySplitWithOneDivergentLegIsMixed(): void
    {
        $this->assertSame('Mixed', DebitOrderSplitLeg::rollupFromStatuses(['Registered', 'Registered', 'Cancelled']));
    }
}
