<?php

namespace App\Services;

/**
 * The seam between DesertLedger's Public Defaults workflow and Creditinfo's
 * actual Public Defaults API. Deliberately unimplemented (see
 * CreditinfoPublicDefaultsClient) until Creditinfo supplies the API
 * specification -- when they do, only the gateway's internals need to
 * change to real HTTP calls; the workflow, database, permissions, and UI
 * built around this interface do not need to be redesigned.
 *
 * $payload is intentionally untyped (array) -- its shape cannot be defined
 * without inventing request fields, which item 13 explicitly forbids.
 */
interface PublicDefaultsGateway
{
    /** @return array Creditinfo's response, once a real implementation exists. */
    public function listIndividual(array $payload): array;

    /** @return array Creditinfo's response, once a real implementation exists. */
    public function removeIndividual(array $payload): array;
}
