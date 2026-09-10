<?php

namespace App\Services;

/** Thrown by every CreditinfoPublicDefaultsClient method -- see its class docblock. Never caught and silently ignored; the controller surfaces its message verbatim and logs the blocked attempt. */
class CreditinfoPublicDefaultsNotConfiguredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Public Defaults API is not yet configured. Creditinfo API documentation is required.');
    }
}
