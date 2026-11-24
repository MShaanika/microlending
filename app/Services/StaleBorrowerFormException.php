<?php

namespace App\Services;

/** The client edit form was built from an older version of the client than what is stored now (someone else saved meanwhile). */
class StaleBorrowerFormException extends \RuntimeException
{
}
