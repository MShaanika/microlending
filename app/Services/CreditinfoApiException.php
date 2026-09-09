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
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $rawBody = null,
    ) {
        parent::__construct($message);
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
