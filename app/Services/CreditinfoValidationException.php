<?php

namespace App\Services;

/** Creditinfo rejected the request shape/content (e.g. a malformed body) -- distinct from an auth failure. */
class CreditinfoValidationException extends CreditinfoApiException
{
}
