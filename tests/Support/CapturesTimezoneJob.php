<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records the app timezone that's active when the job runs, used to verify
 * queue workers pick up timezone changes made after the worker booted.
 */
class CapturesTimezoneJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        cache()->forever('captured-timezone', [
            'config' => config('app.timezone'),
            'default' => date_default_timezone_get(),
        ]);
    }
}
