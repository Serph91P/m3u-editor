<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Debug\ShouldntReport;

class SchedulesDirectLoginCooldownException extends SchedulesDirectRateLimitException implements ShouldntReport
{
    public function __construct(CarbonInterface $retryAt)
    {
        parent::__construct(
            $retryAt,
            'Schedules Direct authentication is paused until '.$retryAt->toIso8601String().'.',
        );
    }
}
