<?php

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Events\EpgCreated;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

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

it('keeps ProcessEpgImport unique through completion per EPG', function () {
    config(['cache.default' => 'array']);
    Queue::fake();
    $epg = Epg::factory()->for(User::factory())->create();
    $job = new ProcessEpgImport($epg);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $epg->id);
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
        ->map(fn (object $queuedJob): int => unserialize(json_decode($queuedJob->payload, true)['data']['command'])->epg->getKey())
        ->sort()
        ->values();

    expect($queuedEpgIds)->toHaveCount(2)
        ->and($queuedEpgIds->all())->toBe($epgs->pluck('id')->sort()->values()->all());
});
