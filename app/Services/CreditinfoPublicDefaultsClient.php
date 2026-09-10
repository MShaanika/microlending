<?php

namespace App\Services;

/**
 * Placeholder Creditinfo Public Defaults API client (item 13). Every method
 * throws CreditinfoPublicDefaultsNotConfiguredException before doing
 * anything else -- no HTTP call, no endpoint URL, no request body is ever
 * built here, because none of that is documented yet:
 *
 *  - No endpoint path is known (Settings holds blank listing_endpoint/
 *    removal_endpoint fields for exactly this reason).
 *  - No request payload shape is known.
 *  - No response format is known.
 *  - No authentication flow is known (do NOT assume it reuses
 *    CreditinfoClient's OAuth2 flow -- that is a guess this class must not
 *    make; keeping Public Defaults on its own client, per item 11, means
 *    that decision is deferred to when the spec actually arrives).
 *
 * Deliberately NOT a subclass or wrapper of CreditinfoClient/
 * CreditinfoBureauClient (the CBS module) -- see the class docblock on
 * CreditinfoPublicDefaultService for why the two integrations are kept on
 * separate service boundaries.
 *
 * When Creditinfo eventually supplies the API documentation, only this
 * class's method bodies need to change to real HTTP calls implementing
 * PublicDefaultsGateway -- the workflow, database, and UI built around the
 * interface do not need to be redesigned.
 */
class CreditinfoPublicDefaultsClient implements PublicDefaultsGateway
{
    public function listIndividual(array $payload): array
    {
        throw new CreditinfoPublicDefaultsNotConfiguredException();
    }

    public function removeIndividual(array $payload): array
    {
        throw new CreditinfoPublicDefaultsNotConfiguredException();
    }
}
