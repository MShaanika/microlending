<?php

namespace App\Services;

/**
 * Creditinfo's SFTP server answered, but refused the saved user name and password. Nothing was sent. Repeating the attempt can lock the account (it
 * happened once with the CBS API), so the automatic submission treats this as a global hard stop: it switches itself off and nothing is tried again until
 * a person has fixed the SFTP settings and acknowledged it on the CPL dashboard.
 */
class CplSftpLoginRefusedException extends \RuntimeException
{
}
