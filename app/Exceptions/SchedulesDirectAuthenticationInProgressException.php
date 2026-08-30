<?php

namespace App\Exceptions;

use Exception;

class SchedulesDirectAuthenticationInProgressException extends Exception
{
    public function __construct()
    {
        parent::__construct('Schedules Direct authentication is already in progress. Please try again shortly.');
    }
}
