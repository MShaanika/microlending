<?php

namespace App\Core;

/**
 * Single registration point for every Events::listen() call in the app.
 * Called once from bootstrap/app.php so both web requests and CLI
 * scripts wire the same listeners.
 *
 * Empty as of Phase 1 (Shared Foundation) -- the event registry exists
 * and business events are ready to be fired, but no framework has a
 * listener yet. SLA (Phase 3), Exceptions (Phase 3), and the general
 * notification dispatcher's triggers land here as later phases build
 * them -- this file is the one place to look to see everything the
 * event system currently does.
 */
class EventListeners
{
    public static function register(): void
    {
        // No listeners yet.
    }
}
