<?php

use App\Filament\Clusters\Settings\Pages\ManageAlertSettings;
use App\Listeners\AlertOnJobFailed;
use App\Listeners\BackupFailed;
use App\Models\User;
use App\Notifications\TelegramAlert;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use NotificationChannels\Telegram\Telegram;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob;
use Spatie\SlackAlerts\Jobs\SendToSlackChannelJob;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(Telegram::class, new Telegram(http: new Client(['handler' => HandlerStack::create(new MockHandler)])));
    // Model an installed application with persisted settings, not missing defaults.
    foreach (app(GeneralSettings::class)->toArray() as $name => $value) {
        DB::table('settings')->insertOrIgnore([
            'group' => 'general',
            'name' => $name,
            'locked' => false,
            'payload' => json_encode($value, JSON_THROW_ON_ERROR),
        ]);
    }
    app(GeneralSettings::class)->refresh();
    $this->actingAs(User::factory()->admin()->create());
    config([
        'queue.default' => 'alert_test',
        'queue.connections.alert_test' => [
            'driver' => 'database',
            'connection' => config('database.default'),
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],
        'discord-alerts.connection' => 'alert_test',
        'discord-alerts.queue' => 'default',
        'slack-alerts.queue' => 'default',
    ]);
});

/** @return array<string, bool|string|null> */
function failedAlertSettings(array $channels): array
{
    return [
        'discord_alerts_enabled' => in_array('discord', $channels, true),
        'discord_webhook_url' => in_array('discord', $channels, true) ? 'https://discord.example.test/webhook' : null,
        'slack_alerts_enabled' => in_array('slack', $channels, true),
        'slack_webhook_url' => in_array('slack', $channels, true) ? 'https://slack.example.test/webhook' : null,
        'telegram_alerts_enabled' => in_array('telegram', $channels, true),
        'telegram_bot_token' => in_array('telegram', $channels, true) ? '1774:synthetic-token' : null,
        'telegram_chat_id' => in_array('telegram', $channels, true) ? '-1001774' : null,
        'alerts_on_job_failed' => true,
    ];
}

function saveFailedAlertSettings(array $data): void
{
    Livewire::test(ManageAlertSettings::class)
        ->fillForm($data)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('Settings saved'));

    app()->forgetInstance(GeneralSettings::class);
    $fresh = app(GeneralSettings::class)->refresh();
    foreach ($data as $key => $value) {
        expect($fresh->{$key})->toBe($value);
    }

    Livewire::test(ManageAlertSettings::class)->assertFormSet($data);
}

function dispatchFailedAlertEvent(string $name = 'SyntheticOrdinaryJob'): void
{
    $queue = app('queue')->connection('alert_test');
    expect($queue)->toBeInstanceOf(DatabaseQueue::class);
    $queue->pushRaw(json_encode([
        'displayName' => $name,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [],
    ], JSON_THROW_ON_ERROR), 'source');
    $job = $queue->pop('source');
    expect($job)->toBeInstanceOf(DatabaseJob::class);
    $job->delete();

    app('events')->dispatch(new JobFailed('alert_test', $job, new RuntimeException('synthetic failure')));
}

it('queues one native delivery per configured channel after saving and remounting settings', function (string $channel, string $class) {
    saveFailedAlertSettings(failedAlertSettings([$channel]));

    dispatchFailedAlertEvent();

    $rows = DB::table('jobs')->where('queue', 'default')->get();
    expect($rows)->toHaveCount(1);
    $payload = json_decode($rows->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['data']['commandName'])->toBe($class);
    Http::assertNothingSent();
})->with([
    'discord' => ['discord', SendToDiscordChannelJob::class],
    'slack' => ['slack', SendToSlackChannelJob::class],
    'telegram' => ['telegram', SendQueuedNotifications::class],
]);

it('delivers every channel for each separate failure without suppressing later events', function () {
    saveFailedAlertSettings(failedAlertSettings(['discord', 'slack', 'telegram']));

    dispatchFailedAlertEvent('FirstSyntheticJob');
    expect(DB::table('jobs')->count())->toBe(3);
    dispatchFailedAlertEvent('SecondSyntheticJob');

    $classes = DB::table('jobs')->where('queue', 'default')->get()
        ->map(fn (object $row): string => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['data']['commandName'])
        ->countBy()->all();
    expect($classes)->toBe([
        SendToDiscordChannelJob::class => 2,
        SendToSlackChannelJob::class => 2,
        SendQueuedNotifications::class => 2,
    ]);
    Http::assertNothingSent();
});

it('does not queue alerts when routing or the failed job toggle is disabled', function (array $overrides) {
    saveFailedAlertSettings(array_replace(failedAlertSettings(['discord', 'slack', 'telegram']), $overrides));

    dispatchFailedAlertEvent();

    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'all channels off' => [[
        'alerts_on_job_failed' => false,
        'discord_alerts_enabled' => false, 'discord_webhook_url' => null,
        'slack_alerts_enabled' => false, 'slack_webhook_url' => null,
        'telegram_alerts_enabled' => false, 'telegram_bot_token' => null, 'telegram_chat_id' => null,
    ]],
    'failed job alerts off' => [['alerts_on_job_failed' => false]],
    'missing webhook urls and telegram token' => [[
        'discord_webhook_url' => null, 'slack_webhook_url' => null, 'telegram_bot_token' => null,
    ]],
    'missing webhook urls and telegram chat' => [[
        'discord_webhook_url' => null, 'slack_webhook_url' => null, 'telegram_chat_id' => null,
    ]],
]);

it('ignores configured channels after disabling their toggles while failed job alerts remain enabled', function () {
    $settings = failedAlertSettings(['discord', 'slack', 'telegram']);
    saveFailedAlertSettings($settings);
    saveFailedAlertSettings(array_replace($settings, [
        'discord_alerts_enabled' => false,
        'slack_alerts_enabled' => false,
        'telegram_alerts_enabled' => false,
    ]));

    dispatchFailedAlertEvent();

    expect(app(GeneralSettings::class)->alerts_on_job_failed)->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not alert again when native alert delivery jobs fail', function (string $name) {
    saveFailedAlertSettings(failedAlertSettings(['discord', 'slack', 'telegram']));

    dispatchFailedAlertEvent($name);

    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    TelegramAlert::class,
    SendToDiscordChannelJob::class,
    SendToSlackChannelJob::class,
]);

/** @return ArrayObject<int, array<string, mixed>> */
function fakeFailedAlertTransports(): ArrayObject
{
    Http::fake([
        'https://discord.example.test/webhook' => Http::response([], 204),
        'https://slack.example.test/webhook' => Http::response([], 200),
    ]);
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"ok":true,"result":{"message_id":1774}}')]));
    $stack->push(Middleware::history($history));
    app()->instance(Telegram::class, new Telegram(http: new Client(['handler' => $stack])));

    return $history;
}

it('consumes one native delivery with the original routing and failure payload', function (string $channel) {
    $history = fakeFailedAlertTransports();
    saveFailedAlertSettings(failedAlertSettings([$channel]));
    dispatchFailedAlertEvent();

    $queue = app('queue')->connection('alert_test');
    expect($queue->size())->toBe(1);
    $delivery = $queue->pop();
    expect($delivery)->toBeInstanceOf(DatabaseJob::class);
    $delivery->fire();
    expect($queue->size())->toBe(0);

    if ($channel === 'telegram') {
        expect($history)->toHaveCount(1);
        $request = $history[0]['request'];
        expect((string) $request->getUri())->toBe('https://api.telegram.org/bot1774:synthetic-token/sendMessage');
        parse_str((string) $request->getBody(), $payload);
        expect($payload['chat_id'])->toBe('-1001774');
        $message = $payload['text'];
        Http::assertNothingSent();
    } else {
        expect($history)->toHaveCount(0);
        Http::assertSentCount(1);
        $request = Http::recorded()->sole()[0];
        expect($request->url())->toBe("https://{$channel}.example.test/webhook");
        $message = $request[$channel === 'discord' ? 'content' : 'text'];
    }

    expect($message)->toContain('[JOB FAILED] SyntheticOrdinaryJob: synthetic failure')
        ->toContain('"queue": "source"')
        ->toContain('"connection": "alert_test"');
})->with(['discord', 'slack', 'telegram']);

it('keeps synchronous settings test sends out of the delivery queue', function (string $channel) {
    $history = fakeFailedAlertTransports();
    saveFailedAlertSettings(failedAlertSettings([$channel]));

    Livewire::test(ManageAlertSettings::class)
        ->callAction(TestAction::make("test_{$channel}_alert")->schemaComponent("{$channel}::data::section"))
        ->assertNotified(__('Test Alert Sent'));

    expect(DB::table('jobs')->count())->toBe(0);
    if ($channel === 'telegram') {
        expect($history)->toHaveCount(1);
        parse_str((string) $history[0]['request']->getBody(), $payload);
        expect($payload['chat_id'])->toBe('-1001774')
            ->and($payload['text'])->toStartWith('[TEST]');
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
    }
})->with(['slack', 'telegram']);

it('keeps the explicit listener and unrelated discovery when booting a freshly generated event cache', function () {
    $discovered = DiscoverEvents::within(app_path('Listeners'), base_path());
    expect($discovered[JobFailed::class] ?? [])->not->toContain(AlertOnJobFailed::class.'@handle')
        ->and($discovered[BackupHasFailed::class])->toContain(BackupFailed::class.'@handle')
        ->and(app('events')->getRawListeners()[JobFailed::class])->toContain(AlertOnJobFailed::class);

    $cache = tempnam(sys_get_temp_dir(), 'alert-events-');
    $environment = ['APP_EVENTS_CACHE' => $cache, 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'BROADCAST_CONNECTION' => 'null'];
    try {
        $cacheProcess = new Process([PHP_BINARY, 'artisan', 'event:cache', '--no-interaction'], base_path(), $environment);
        $cacheProcess->mustRun();
        expect($cacheProcess->getOutput())->toContain('Events cached successfully');
        $manifest = require $cache;
        expect($manifest[EventServiceProvider::class][JobFailed::class] ?? [])
            ->not->toContain(AlertOnJobFailed::class.'@handle');

        $listProcess = new Process([PHP_BINARY, 'artisan', 'event:list', '--json', '--no-interaction'], base_path(), $environment);
        $listProcess->mustRun();
        $events = collect(json_decode($listProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR))->keyBy('event');
        expect(collect($events[JobFailed::class]['listeners'])->filter(fn (string $listener): bool => str_contains($listener, AlertOnJobFailed::class))->values()->all())
            ->toBe([AlertOnJobFailed::class])
            ->and($events[BackupHasFailed::class]['listeners'])
            ->toContain(BackupFailed::class.'@handle (ShouldQueue)');
    } finally {
        unlink($cache);
    }
});
