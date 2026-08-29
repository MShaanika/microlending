<?php

namespace App\Core;

/**
 * One correlation ID per request (or per CLI invocation), threaded
 * through Audit::log() and SecurityEvent::record() automatically so any
 * operation can be traced end to end -- user request -> controller ->
 * service -> audit trail -> security event -> exception -- by one
 * string. Format: REQ-YYYYMMDD-XXXXXXXX.
 *
 * Issued eagerly at the top of Router::dispatch() for web requests; for
 * CLI scripts (bin/*.php never calls dispatch()) it lazily generates on
 * first access instead, so any Audit/SecurityEvent write from a cron
 * script still gets a real ID with no extra wiring required.
 *
 * Static/request-scoped by design, matching this app's existing
 * Session/ClientIp helper style -- not passed through every function
 * signature.
 */
class Correlation
{
    private static ?string $id = null;

    public static function id(): string
    {
        if (self::$id === null) {
            self::$id = self::generate();
        }
        return self::$id;
    }

    /** Lets a caller adopt an externally-supplied ID (e.g. a webhook that carries its own reference) instead of minting a fresh one. */
    public static function set(string $id): void
    {
        self::$id = $id;
    }

    /** Test-only: clears the current ID so each test case starts fresh instead of inheriting the previous test's. */
    public static function resetForTesting(): void
    {
        self::$id = null;
    }

    private static function generate(): string
    {
        return 'REQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
