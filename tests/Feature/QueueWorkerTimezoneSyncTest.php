<?php

use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CapturesTimezoneJob;

uses(RefreshDatabase::class);

// Regression for https://github.com/m3ue/m3u-editor/issues/1449: scheduled
// backup filenames were generated in UTC even when the app_timezone setting
// was changed to something else. Root cause: Horizon queue workers boot the
// application once and stay alive for days, so a timezone change made via
// Preferences after a worker started was never picked up by that worker,
// while a fresh web request re-applied it every time — producing a filename
// (worker's stale timezone) that didn't match the "Date" column (re-resolved
// per request). AppServiceProvider now re-applies the timezone before every
// queued job via a JobProcessing listener, so this must hold even when the
// process-wide config was left stale from an earlier boot.
it('re-applies the configured timezone before a queued job runs, even on a stale worker', function () {
    config(['dev.timezone' => null]);

    $settings = new GeneralSettings;
    $settings->app_timezone = 'Asia/Tokyo';
    app()->instance(GeneralSettings::class, $settings);

    // Simulate a long-lived Horizon worker that booted before the timezone
    // setting above was saved, and therefore still has the old value applied.
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    dispatch(new CapturesTimezoneJob)->onConnection('sync');

    expect(cache('captured-timezone'))->toBe([
        'config' => 'Asia/Tokyo',
        'default' => 'Asia/Tokyo',
    ]);
});
