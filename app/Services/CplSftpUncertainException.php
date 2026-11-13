<?php

namespace App\Services;

/**
 * The SFTP connection failed AFTER file data may already have been written: the file may or may not be on
 * Creditinfo's server. Callers must say so plainly (never "nothing was sent") and must not record the file as
 * uploaded, because the honest next step is to check with Creditinfo before sending it again.
 */
class CplSftpUncertainException extends \RuntimeException
{
}
