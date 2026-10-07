<?php

namespace App\Services;

/**
 * Thrown for a Creditinfo CBS REST API failure. Unlike CollexiaApiException,
 * the supplied documentation (NAM_CBS_WS_Report_Manual_JSON.pdf, 35 pages,
 * and the vendor's own Postman collection) never shows an actual ERROR
 * response body for the search/report/pdf endpoints -- only success and
 * NIL-report examples, both of which carry "status": "Success" at the top
 * level. This class therefore does NOT assume a specific error JSON shape
 * (no invented {errors:[...]} structure) -- it carries the HTTP status and
 * raw response body verbatim, letting a caller inspect them, rather than
 * pretending to have parsed fields the vendor never documented.
 *
 * $rawBody may contain response content from Creditinfo -- callers must
 * never pass it to Audit::log() or any logging path verbatim (see
 * CreditinfoClient::lastDebug()'s own scrubbing for the same rule applied
 * to requests/responses generally).
 */
class CreditinfoApiException extends \RuntimeException
{
    // Plain (not `readonly`) promoted properties -- this codebase's
    // deployed PHP is 8.0, which does not support readonly properties
    // (an 8.1 feature); the earlier `readonly` version was a latent parse
    // error that only surfaced when this class was actually instantiated
    // (i.e. on any Creditinfo CBS API failure), never during a successful
    // call. Found and fixed 2026-09-30 while live-testing the CBS
    // connection -- see App\Services\CreditinfoAuthException, which
    // extends this class and would otherwise fail to load too.
    private ?int $httpStatus;
    private ?string $rawBody;

    public function __construct(string $message, ?int $httpStatus = null, ?string $rawBody = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->rawBody = $rawBody;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }
}
