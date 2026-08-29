<?php

namespace App\Core;

/**
 * In-process publish/subscribe for the business events named in the
 * Enterprise Control Architecture (LoanApproved, PaymentReceived, ...).
 *
 * Deliberately NOT a queued/durable event bus -- this app has no queue
 * or worker infrastructure (see the Phase 0 audit), so listeners run
 * inline, synchronously, in the same request/script that fired the
 * event. A listener throwing must never be able to break the operation
 * that fired the event (e.g. a broken SLA listener must not stop a loan
 * from being approved) -- fire() isolates each listener in its own
 * try/catch and logs failures rather than propagating them.
 *
 * Registered once per request/script (see EventListeners::register(),
 * called from bootstrap/app.php) -- listeners are not persisted between
 * requests.
 */
class Events
{
    /** @var array<string, list<callable>> */
    private static array $listeners = [];

    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /** @param array<string, mixed> $payload */
    public static function fire(string $event, array $payload = []): void
    {
        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (\Throwable $e) {
                error_log("Events::fire('$event') listener failed: " . $e->getMessage());
            }
        }
    }

    /** Test-only: clears every registered listener so test cases don't leak state into each other. */
    public static function resetForTesting(): void
    {
        self::$listeners = [];
    }
}
