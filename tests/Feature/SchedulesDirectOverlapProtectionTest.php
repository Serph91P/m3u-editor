<?php

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Events\EpgCreated;
use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessEpgImportComplete;
use App\Models\Epg;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::setDefaultDriver('array');
});

it('routes SchedulesDirect EPG syncs onto the dedicated single-worker queue', function () {
    Queue::fake();

    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'shared@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-NY12345-X',
    ]);

    dispatch(new ProcessEpgImport($epg, force: true));

    Queue::assertPushedOn('schedules-direct', ProcessEpgImport::class);
});

it('does not route non-SchedulesDirect EPG syncs onto the SD-only queue', function () {
    Queue::fake();

    $user = User::factory()->create();

    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'source_type' => EpgSourceType::URL,
        'url' => 'https://example.com/epg.xml',
    ]);

    dispatch(new ProcessEpgImport($epg, force: true));

    Queue::assertPushed(ProcessEpgImport::class, function (ProcessEpgImport $job) {
        return $job->queue !== 'schedules-direct';
    });
});

it('does not let a forced dispatch bypass an active EPG import reservation', function () {
    Queue::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create(), [EpgCreated::class]);

    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue()
        ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeFalse();
    Queue::assertPushed(ProcessEpgImport::class, 1);
});

it('releases an EPG import reservation after successful chain completion', function () {
    Queue::fake();
    Notification::fake();
    Event::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'channel_count' => 1,
    ]), [EpgCreated::class]);

    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue();
    $rootJob = Queue::pushed(ProcessEpgImport::class)->sole();

    (new ProcessEpgImportComplete(
        $epg->user_id,
        $epg->id,
        'reservation-success',
        now(),
        $rootJob->reservationOwner,
    ))->handle();

    expect(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
});

it('releases an EPG import reservation after terminal chain failure', function () {
    Queue::fake();
    Notification::fake();
    Event::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'auto_resync_on_failure' => false,
    ]), [EpgCreated::class]);

    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue();
    $rootJob = Queue::pushed(ProcessEpgImport::class)->sole();

    ProcessEpgImport::handleImportChainFailure(
        $epg->id,
        $rootJob->reservationOwner,
        new RuntimeException('controlled chain failure'),
    );

    expect(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
});

it('retains the reservation until a completion exception reaches the chain failure callback', function () {
    Queue::fake();
    Notification::fake();
    Event::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'status' => Status::Processing,
        'auto_resync_on_failure' => false,
    ]), [EpgCreated::class]);

    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue();
    $rootJob = Queue::pushed(ProcessEpgImport::class)->sole();
    $completion = new ProcessEpgImportComplete(
        PHP_INT_MAX,
        $epg->id,
        'reservation-completion-failure',
        now(),
        $rootJob->reservationOwner,
    );

    try {
        $completion->handle();
    } catch (Throwable $throwable) {
        ProcessEpgImport::handleImportChainFailure($epg->id, $rootJob->reservationOwner, $throwable);
    }

    expect($epg->fresh()->status)->toBe(Status::Failed)
        ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
});

it('atomically retains an EPG import reservation for a delayed retry', function () {
    Queue::fake();
    Notification::fake();
    Event::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 1,
        'resync_attempt' => 0,
    ]), [EpgCreated::class]);

    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue();
    $rootJob = Queue::pushed(ProcessEpgImport::class)->sole();

    ProcessEpgImport::handleImportChainFailure(
        $epg->id,
        $rootJob->reservationOwner,
        new RuntimeException('controlled retryable failure'),
    );

    $retry = Queue::pushed(ProcessEpgImport::class)->last();
    expect($retry->reservationOwner)->toBe($rootJob->reservationOwner)
        ->and($retry->delay)->not->toBeNull()
        ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeFalse();

    $retry->failed(new RuntimeException('controlled terminal retry failure'));

    expect(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
});

it('preserves visible EPG state when a Filament process dispatch is rejected', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $epg = Event::fakeFor(fn () => Epg::factory()->for($user)->create([
        'status' => Status::Completed,
        'progress' => 73,
        'sd_progress' => 42,
        'cache_progress' => 31,
        'resync_attempt' => 2,
    ]), [EpgCreated::class]);

    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue();
    $rootJob = Queue::pushed(ProcessEpgImport::class)->sole();

    Livewire::test(ListEpgs::class)
        ->loadTable()
        ->callAction(TestAction::make('process')->table($epg));

    expect($epg->fresh()->only([
        'status',
        'progress',
        'sd_progress',
        'cache_progress',
        'resync_attempt',
    ]))->toBe([
        'status' => Status::Completed,
        'progress' => 73.0,
        'sd_progress' => 42.0,
        'cache_progress' => 31.0,
        'resync_attempt' => 2,
    ]);

    $rootJob->failed(new RuntimeException('test cleanup'));
});

it('enforces the EPG import reservation with the real Redis queue and cache', function () {
    $redisHost = getenv('TEST_REDIS_HOST');

    if ($redisHost === false) {
        $this->markTestSkipped('Real Redis assertion requires TEST_REDIS_HOST.');
    }

    config([
        'database.redis.client' => 'predis',
        'database.redis.default.host' => $redisHost,
        'database.redis.default.port' => (int) (getenv('TEST_REDIS_PORT') ?: 6379),
        'database.redis.default.database' => (int) (getenv('TEST_REDIS_DATABASE') ?: 15),
        'cache.default' => 'redis',
        'cache.epg_import_reservation_store' => 'redis',
        'cache.stores.redis.connection' => 'default',
        'queue.default' => 'redis',
        'queue.connections.redis.connection' => 'default',
    ]);
    Cache::setDefaultDriver('redis');
    Queue::setDefaultDriver('redis');
    $queue = Queue::connection('redis');
    $queue->clear('schedules-direct');
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'redis-reservation@example.com',
        'sd_password' => 'password',
        'sd_lineup_id' => 'USA-NY12345-X',
    ]), [EpgCreated::class]);
    $job = new ProcessEpgImport($epg, force: true);

    try {
        app(Dispatcher::class)->dispatch($job);

        expect($job->reservationOwner)->toBeString()
            ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeFalse()
            ->and($queue->size('schedules-direct'))->toBe(1);
    } finally {
        $job->failed(new RuntimeException('test cleanup'));
        $queue->clear('schedules-direct');
        Cache::setDefaultDriver('array');
        Queue::setDefaultDriver('sync');
    }
});

it('releases the real Redis reservation when the EPG is deleted before queue execution', function () {
    $redisHost = getenv('TEST_REDIS_HOST');

    if ($redisHost === false) {
        $this->markTestSkipped('Real Redis assertion requires TEST_REDIS_HOST.');
    }

    config([
        'database.redis.client' => 'predis',
        'database.redis.default.url' => null,
        'database.redis.default.host' => $redisHost,
        'database.redis.default.port' => (int) (getenv('TEST_REDIS_PORT') ?: 6379),
        'database.redis.default.database' => (int) (getenv('TEST_REDIS_DATABASE') ?: 15),
        'cache.default' => 'redis',
        'cache.epg_import_reservation_store' => 'redis',
        'cache.stores.redis.connection' => 'default',
        'queue.default' => 'redis',
        'queue.connections.redis.connection' => 'default',
    ]);
    Cache::setDefaultDriver('redis');
    Queue::setDefaultDriver('redis');
    $queue = Queue::connection('redis');
    $queue->clear('schedules-direct');
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'redis-missing-model@example.com',
        'sd_password' => 'password',
        'sd_lineup_id' => 'USA-NY12345-X',
    ]), [EpgCreated::class]);
    $epgId = $epg->id;

    expect(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
    $queuedJob = $queue->pop('schedules-direct');
    expect($queuedJob)->not->toBeNull();
    $commandPayload = json_decode($queuedJob->getRawBody(), true, flags: JSON_THROW_ON_ERROR)['data']['command'];
    $reservationOwner = unserialize($commandPayload)->reservationOwner;
    $epg->delete();

    try {
        $queuedJob->fire();
        $replacementReservation = Cache::store('redis')->lock('epg-import:execution:'.$epgId);
        $reservationAcquired = $replacementReservation->get();

        expect($reservationAcquired)->toBeTrue()
            ->and($commandPayload)->not->toContain(ModelIdentifier::class);

        if ($reservationAcquired) {
            $replacementReservation->release();
        }
    } finally {
        ProcessEpgImport::releaseReservation($epgId, $reservationOwner);
        $queue->clear('schedules-direct');
        Cache::setDefaultDriver('array');
        Queue::setDefaultDriver('sync');
    }
});

it('does not enqueue duplicate failed EPG imports across repeated refresh invocations', function () {
    config([
        'cache.default' => 'array',
        'queue.default' => 'database',
        'dev.failed_retry_cooldown_minutes' => 30,
    ]);
    $user = User::factory()->create();
    $epgs = Event::fakeFor(fn () => collect(range(1, 2))->map(fn (int $index): Epg => Epg::factory()->for($user)->create([
        'name' => "Failed EPG {$index}",
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => "account-{$index}@example.com",
        'sd_password' => 'password',
        'sd_lineup_id' => 'USA-NY12345-X',
        'auto_sync' => true,
        'sync_interval' => '* * * * *',
        'status' => Status::Failed,
        'synced' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ])), [EpgCreated::class]);

    $this->artisan('app:refresh-epg')->assertSuccessful();
    $this->artisan('app:refresh-epg')->assertSuccessful();

    $queuedEpgIds = DB::table('jobs')
        ->where('queue', 'schedules-direct')
        ->get()
        ->map(fn (object $queuedJob): int => unserialize(json_decode($queuedJob->payload, true)['data']['command'])->epgId)
        ->sort()
        ->values();

    expect($queuedEpgIds)->toHaveCount(2)
        ->and($queuedEpgIds->all())->toBe($epgs->pluck('id')->sort()->values()->all());
});
