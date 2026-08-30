<?php

namespace App\Exceptions;

use DateTimeInterface;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

class SchedulesDirectLoginCooldownException extends Exception implements ShouldntReport
{
    public function __construct(public readonly DateTimeInterface $retryAt)
    {
        parent::__construct(
            'Schedules Direct authentication is paused until '.$retryAt->format(DateTimeInterface::ATOM).'.',
            4009,
        );
    }
}
