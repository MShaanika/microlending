<?php

namespace App\Services;

/** A curl timeout, or an async report/pdf poll that settled on requestStatus "Timeout". */
class CreditinfoTimeoutException extends CreditinfoApiException
{
}
