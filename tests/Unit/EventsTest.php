<?php

namespace Tests\Unit;

use App\Core\Events;
use PHPUnit\Framework\TestCase;

class EventsTest extends TestCase
{
    protected function setUp(): void
    {
        Events::resetForTesting();
    }

    public function testListenerReceivesTheFiredPayload(): void
    {
        $received = null;
        Events::listen('LoanApproved', function (array $payload) use (&$received) {
            $received = $payload;
        });

        Events::fire('LoanApproved', ['loan_id' => 42]);

        $this->assertSame(['loan_id' => 42], $received);
    }

    public function testMultipleListenersOnTheSameEventAllRun(): void
    {
        $calls = [];
        Events::listen('PaymentReceived', function () use (&$calls) { $calls[] = 'first'; });
        Events::listen('PaymentReceived', function () use (&$calls) { $calls[] = 'second'; });

        Events::fire('PaymentReceived', []);

        $this->assertSame(['first', 'second'], $calls);
    }

    public function testFiringAnEventWithNoListenersIsANoOp(): void
    {
        // Must not throw or warn -- most events have no listener yet
        // (Phase 1: only the registry exists, see EventListeners).
        Events::fire('SomeEventNobodyListensToYet', ['x' => 1]);
        $this->assertTrue(true);
    }

    public function testAListenerThrowingDoesNotStopTheOperationThatFiredTheEvent(): void
    {
        $secondRan = false;
        Events::listen('LoanDisbursed', function () { throw new \RuntimeException('a broken listener'); });
        Events::listen('LoanDisbursed', function () use (&$secondRan) { $secondRan = true; });

        // fire() must swallow the listener's exception -- a broken SLA
        // listener, for example, must never be able to stop a real
        // disbursement from completing.
        Events::fire('LoanDisbursed', []);

        $this->assertTrue($secondRan);
    }
}
