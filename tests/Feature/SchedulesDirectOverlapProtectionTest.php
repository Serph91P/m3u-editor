<?php

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Events\EpgCreated;
use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessEpgImportChunk;
use App\Jobs\ProcessEpgImportComplete;
use App\Models\Epg;
use App\Models\Job;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class LegacyProcessEpgImportPayload
{
    use SerializesModels;

    public function __construct(public Epg $epg, public ?bool $force = false) {}
}

class LegacyProcessEpgImportCompletePayload
{
    public function __construct(
        public int $userId,
        public int $epgId,
        public string $batchNo,
        public Carbon\Carbon $start,
    ) {}
}

class LegacyProcessEpgImportChunkPayload
{
    public function __construct(public array $jobs, public int $batchCount) {}
}

function serializeLegacyEpgJobAs(object $payload, string $targetClass): string
{
    return preg_replace(
        '/^O:\d+:"'.preg_quote($payload::class, '/').'"/',
        'O:'.strlen($targetClass).':"'.$targetClass.'"',
        serialize($payload),
    );
}

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

    Queue::assertPushedOn(
        'schedules-direct',
        ProcessEpgImport::class,
        fn (ProcessEpgImport $job): bool => $job->epg === null
            && ! str_contains(serialize($job), ModelIdentifier::class),
    );
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

it('retains a queued retry when its post-dispatch notification fails', function () {
    Queue::fake();
    Event::listen(NotificationSending::class, function (NotificationSending $event): void {
        if (($event->notification->data['title'] ?? null) === 'EPG resync queued') {
            throw new RuntimeException('controlled retry notification failure');
        }
    });
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
        ->and($epg->fresh()->resync_attempt)->toBe(1)
        ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeFalse();
    $retry->failed(new RuntimeException('test cleanup'));
    expect(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
});

it('renews only an owner-matched bounded reservation', function () {
    Queue::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create(), [EpgCreated::class]);

    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue();
    $job = Queue::pushed(ProcessEpgImport::class)->sole();
    $oldOwner = $job->reservationOwner;
    ProcessEpgImport::forceReleaseReservation($epg->id);
    expect(ProcessEpgImport::dispatchIfAvailable($epg))->toBeTrue();
    $replacement = Queue::pushed(ProcessEpgImport::class)->last();

    expect(ProcessEpgImport::refreshReservation($epg->id, $oldOwner))->toBeFalse()
        ->and(ProcessEpgImport::refreshReservation($epg->id, $replacement->reservationOwner))->toBeTrue();
    $replacement->failed(new RuntimeException('test cleanup'));
});

it('retains a retry after a legacy completion post-dispatch update fails', function () {
    Queue::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'status' => Status::Processing,
        'channel_count' => 0,
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 1,
        'resync_attempt' => 0,
    ]), [EpgCreated::class]);
    DB::listen(function ($query): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update') && str_contains($query->sql, 'epgs')) {
            throw new RuntimeException('controlled legacy update failure');
        }
    });
    $completion = unserialize(serializeLegacyEpgJobAs(
        new LegacyProcessEpgImportCompletePayload($epg->user_id, $epg->id, 'legacy-retry', now()),
        ProcessEpgImportComplete::class,
    ));

    expect(fn () => $completion->handle())
        ->toThrow(RuntimeException::class, 'controlled legacy update failure');

    $retry = Queue::pushed(ProcessEpgImport::class)->sole();
    expect($retry->reservationOwner)->toBe($completion->reservationOwner)
        ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeFalse();

    $retry->failed(new RuntimeException('test cleanup'));
    expect(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
});

it('restores legacy root and completion payloads without concurrent processing', function () {
    Queue::fake();
    Notification::fake();
    Event::fake();
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'status' => Status::Processing,
        'channel_count' => 1,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'legacy-payload@example.com',
        'sd_password' => 'password',
        'sd_lineup_id' => 'USA-NY12345-X',
        'sd_login_cooldown_until' => now()->addHour(),
    ]), [EpgCreated::class]);

    $legacyRoot = unserialize(serializeLegacyEpgJobAs(
        new LegacyProcessEpgImportPayload($epg, true),
        ProcessEpgImport::class,
    ));
    $legacyRoot->handle(app(SchedulesDirectService::class));
    expect($legacyRoot->epgId)->toBe($epg->id)
        ->and($legacyRoot->reservationOwner)->toBeString();

    $legacyCompletion = unserialize(serializeLegacyEpgJobAs(
        new LegacyProcessEpgImportCompletePayload($epg->user_id, $epg->id, 'legacy-completion', now()),
        ProcessEpgImportComplete::class,
    ));
    $legacyCompletion->handle();

    expect($epg->fresh()->status)->toBe(Status::Completed)
        ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
});

it('fences legacy chunk payloads with a batch compatibility reservation', function () {
    Queue::fake();
    Notification::fake();
    Event::fake();
    $epg = Epg::factory()->for(User::factory())->create([
        'status' => Status::Processing,
        'channel_count' => 1,
    ]);
    $job = Job::create([
        'title' => 'Legacy EPG chunk',
        'batch_no' => 'legacy-chunk-batch',
        'payload' => [],
        'variables' => ['epgId' => $epg->id],
    ]);
    $legacyChunk = unserialize(serializeLegacyEpgJobAs(
        new LegacyProcessEpgImportChunkPayload([$job->id], 1),
        ProcessEpgImportChunk::class,
    ));

    $legacyChunk->handle();

    expect($legacyChunk->epgId)->toBe($epg->id)
        ->and($legacyChunk->reservationOwner)->toBeString()
        ->and(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeFalse();

    $legacyCompletion = unserialize(serializeLegacyEpgJobAs(
        new LegacyProcessEpgImportCompletePayload($epg->user_id, $epg->id, 'legacy-chunk-batch', now()),
        ProcessEpgImportComplete::class,
    ));
    $legacyCompletion->handle();

    expect(ProcessEpgImport::dispatchIfAvailable($epg, force: true))->toBeTrue();
    Queue::pushed(ProcessEpgImport::class)->last()->failed(new RuntimeException('test cleanup'));
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
        $store = Cache::store('redis')->getStore();
        expect($store->lockConnection()->ttl($store->getPrefix().'epg-import:execution:'.$epg->id))->toBeGreaterThan(0);
        ProcessEpgImport::forceReleaseReservation($epg->id);
        $replacementOwner = ProcessEpgImport::acquireCompatibilityReservation($epg->id, 'redis-replacement');
        expect($replacementOwner)->toBeString()
            ->and(ProcessEpgImport::refreshReservation($epg->id, $job->reservationOwner))->toBeFalse()
            ->and(ProcessEpgImport::refreshReservation($epg->id, $replacementOwner))->toBeTrue();
        ProcessEpgImport::forgetCompatibilityReservation($epg->id, 'redis-replacement');
        ProcessEpgImport::releaseReservation($epg->id, $replacementOwner);
    } finally {
        $job->failed(new RuntimeException('test cleanup'));
        $queue->clear('schedules-direct');
        Cache::setDefaultDriver('array');
        Queue::setDefaultDriver('sync');
    }
});

it('keeps an active real Redis reservation during reset', function () {
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
    Notification::fake();
    $queue = Queue::connection('redis');
    $queue->clear('schedules-direct');
    $epg = Event::fakeFor(fn () => Epg::factory()->for(User::factory())->create([
        'status' => Status::Processing,
        'processing' => true,
        'processing_phase' => 'import',
        'auto_sync' => true,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'redis-reset@example.com',
        'sd_password' => 'password',
        'sd_lineup_id' => 'USA-NY12345-X',
    ]), [EpgCreated::class]);
    $oldOwner = ProcessEpgImport::acquireCompatibilityReservation($epg->id, 'redis-reset');

    try {
        expect($oldOwner)->toBeString();
        $this->artisan('app:reset-sync-process')->assertSuccessful();

        expect($queue->size('schedules-direct'))->toBe(0)
            ->and(ProcessEpgImport::refreshReservation($epg->id, $oldOwner))->toBeTrue()
            ->and($epg->fresh()->status)->toBe(Status::Processing);
    } finally {
        ProcessEpgImport::forgetCompatibilityReservation($epg->id, 'redis-reset');
        ProcessEpgImport::releaseReservation($epg->id, $oldOwner);
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
    $reservationOwner = unserialize(
        $commandPayload,
        ['allowed_classes' => [ProcessEpgImport::class]],
    )->reservationOwner;
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
        ->map(fn (object $queuedJob): int => unserialize(
            json_decode($queuedJob->payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'],
            ['allowed_classes' => [ProcessEpgImport::class]],
        )->epgId)
        ->sort()
        ->values();

    expect($queuedEpgIds)->toHaveCount(2)
        ->and($queuedEpgIds->all())->toBe($epgs->pluck('id')->sort()->values()->all());
});
