<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheEnrichmentService;
use App\Services\EpgCacheGenerationResolver;
use App\Services\EpgCacheService;
use App\Services\EpgProgrammeStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Bus::fake();
    Storage::fake('local');
});

function enrichmentContext(User $user, array $overrides = []): PluginExecutionContext
{
    $plugin = Plugin::query()->create(array_merge([
        'plugin_id' => 'epg-enrichment-'.fake()->uuid(),
        'name' => 'EPG Enrichment Fixture',
        'version' => '1.0.0',
        'api_version' => '1.0.0',
        'description' => 'Fixture',
        'capabilities' => ['epg_cache_enrichment'],
        'hooks' => [],
        'permissions' => [],
        'schema_definition' => ['tables' => []],
        'actions' => [],
        'settings_schema' => [],
        'settings' => [],
        'data_ownership' => ['tables' => [], 'directories' => [], 'files' => []],
        'source_type' => 'local_directory',
        'path' => storage_path('app/testing-plugin-sources/'.fake()->uuid()),
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'validation_status' => 'valid',
        'integrity_status' => 'verified',
    ], $overrides));
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'trigger' => 'manual',
        'invocation_type' => 'action',
        'dry_run' => false,
        'payload' => [],
    ]);

    return new PluginExecutionContext($plugin, $run, 'manual', false, null, $user, []);
}

function completeEnrichmentGeneration(Epg $epg, array $programmes): string
{
    $resolver = app(EpgCacheGenerationResolver::class);
    $directory = $resolver->createGenerationDirectory($epg);
    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path("{$directory}/programmes.sqlite"));
    foreach ($programmes as $programme) {
        $start = Carbon::parse($programme['start']);
        $store->insert($programme['channel'], $start->format('Y-m-d'), $start->getTimestamp(), null, $programme);
    }
    $store->finish();
    Storage::disk('local')->put("{$directory}/metadata.json", json_encode(['cache_version' => 'v2'], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put("{$directory}/channels.json", '{}');
    $resolver->publish($epg, $directory);

    return $directory;
}

it('snapshots an owned active generation and conditionally applies an enrichment patch', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original title',
    ]]);
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, ['limit' => 1]);

    expect($snapshot['status'])->toBe('ok')
        ->and($snapshot['programmes'])->toHaveCount(1)
        ->and($snapshot['programmes'][0]['programme']['title'])->toBe('Original title')
        ->and($snapshot['programmes'][0])->not->toHaveKey('sqlite_path');

    $result = $service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Enriched title'],
    ]]);

    $activeStore = EpgProgrammeStore::openRead(Storage::disk('local')->path(app(EpgCacheGenerationResolver::class)->resolve($epg).'/programmes.sqlite'));
    try {
        expect($result['status'])->toBe('applied')
            ->and($activeStore->read('2026-09-16', ['channel.one'])['channel.one'][0]['title'])->toBe('Enriched title');
    } finally {
        $activeStore->close();
    }
});

it('rejects untrusted, unowned, and missing-capability contexts', function (): void {
    $owner = User::factory()->create();
    $epg = Epg::factory()->for($owner)->create();
    completeEnrichmentGeneration($epg, []);
    $service = app(EpgCacheEnrichmentService::class);

    expect($service->snapshot(enrichmentContext($owner, ['capabilities' => []]), $epg)['status'])->toBe('capability_denied')
        ->and($service->snapshot(enrichmentContext($owner, ['trust_state' => 'pending_review']), $epg)['status'])->toBe('plugin_not_trusted')
        ->and($service->snapshot(enrichmentContext(User::factory()->create()), $epg)['status'])->toBe('ownership_denied');
});

it('keeps legacy caches readable but rejects mutation', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    seedEpgProgrammeCache($epg, [['channel.one', [...EpgProgrammeStore::EMPTY_PROGRAMME, 'channel' => 'channel.one', 'start' => '2026-09-16T01:00:00.000000Z', 'title' => 'Legacy']]]);
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);

    expect($snapshot['status'])->toBe('ok')
        ->and($service->apply($context, $epg, $snapshot['token'], [])['status'])->toBe('legacy_cache_read_only');
});

it('bounds snapshot pages and rejects stale or malformed conditional patches', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    $context = enrichmentContext($user);
    $service = app(EpgCacheEnrichmentService::class);
    $snapshot = $service->snapshot($context, $epg, ['limit' => 1]);

    expect($service->snapshot($context, $epg, ['limit' => 101])['status'])->toBe('invalid_selection')
        ->and($service->apply($context, $epg, $snapshot['token'], [[
            'locator' => $snapshot['programmes'][0]['locator'],
            'row_revision' => $snapshot['programmes'][0]['row_revision'],
            'changes' => ['unknown' => 'field'],
        ]])['status'])->toBe('conflict');

    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Replacement',
    ]]);

    expect($service->apply($context, $epg, $snapshot['token'], [])['status'])->toBe('stale_snapshot');
});

it('invalidates both XMLTV representations for every affected playlist after publishing', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create();
    Channel::factory()->for($user)->for($playlist)->create(['epg_channel_id' => $epgChannel->id]);
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist), '<tv/>');
    Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist, true), 'gzip');
    $context = enrichmentContext($user);
    $service = app(EpgCacheEnrichmentService::class);
    $snapshot = $service->snapshot($context, $epg, []);

    $result = $service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]]);

    expect($result['status'])->toBe('applied')
        ->and(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist)))->toBeFalse()
        ->and(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist, true)))->toBeFalse();
});

it('keeps legacy JSONL caches snapshot readable and mutation read-only', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $legacyDirectory = "epg-cache/{$epg->uuid}/v1";
    $programme = [
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Legacy JSONL',
    ];
    Storage::disk('local')->put("{$legacyDirectory}/metadata.json", '{"cache_version":"v1"}');
    Storage::disk('local')->put("{$legacyDirectory}/programmes-2026-09-16.jsonl", json_encode([
        'channel' => 'channel.one',
        'programme' => $programme,
    ], JSON_THROW_ON_ERROR)."\n");

    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, ['limit' => 1]);

    expect($snapshot['status'])->toBe('ok')
        ->and($snapshot['programmes'])->toHaveCount(1)
        ->and($snapshot['programmes'][0]['programme']['title'])->toBe('Legacy JSONL')
        ->and($service->apply($context, $epg, $snapshot['token'], [])['status'])->toBe('legacy_cache_read_only');
});

it('bounds a 500-programme snapshot without a mutation lock and advances its opaque cursor', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $programmes = [];
    for ($index = 0; $index < 500; $index++) {
        $programmes[] = [
            ...EpgProgrammeStore::EMPTY_PROGRAMME,
            'channel' => 'channel.one',
            'start' => Carbon::parse('2026-09-16 00:00:00 UTC')->addMinutes($index)->toISOString(),
            'title' => "Programme {$index}",
        ];
    }
    completeEnrichmentGeneration($epg, $programmes);

    Cache::shouldReceive('lock')->never();
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $firstPage = $service->snapshot($context, $epg, ['limit' => 100]);
    $secondPage = $service->snapshot($context, $epg, ['limit' => 100, 'cursor' => $firstPage['next_cursor']]);

    expect($firstPage['status'])->toBe('ok')
        ->and($firstPage['programmes'])->toHaveCount(100)
        ->and($firstPage['next_cursor'])->toBeString()
        ->and($secondPage['programmes'])->toHaveCount(100)
        ->and($secondPage['programmes'][0]['locator'])->not->toBe($firstPage['programmes'][0]['locator'])
        ->and($service->snapshot($context, $epg, ['cursor' => 'not-a-cursor'])['status'])->toBe('invalid_cursor');
});

it('denies disabled, unavailable, uninstalled, untrusted, invalid, and altered plugins', function (): void {
    $owner = User::factory()->create();
    $epg = Epg::factory()->for($owner)->create();
    completeEnrichmentGeneration($epg, []);
    $service = app(EpgCacheEnrichmentService::class);

    expect($service->snapshot(enrichmentContext($owner, ['enabled' => false]), $epg)['status'])->toBe('plugin_not_enabled')
        ->and($service->snapshot(enrichmentContext($owner, ['available' => false]), $epg)['status'])->toBe('plugin_not_enabled')
        ->and($service->snapshot(enrichmentContext($owner, ['installation_status' => 'uninstalled']), $epg)['status'])->toBe('plugin_not_enabled')
        ->and($service->snapshot(enrichmentContext($owner, ['trust_state' => 'pending_review']), $epg)['status'])->toBe('plugin_not_trusted')
        ->and($service->snapshot(enrichmentContext($owner, ['validation_status' => 'invalid']), $epg)['status'])->toBe('plugin_not_trusted')
        ->and($service->snapshot(enrichmentContext($owner, ['integrity_status' => 'changed']), $epg)['status'])->toBe('plugin_not_trusted');
});

it('rejects malformed, zero-match, duplicate, and stale row patches without invalidating outputs', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create();
    Channel::factory()->for($user)->for($playlist)->create(['epg_channel_id' => $epgChannel->id]);
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist), '<tv/>');
    Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist, true), 'gzip');
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $patch = [
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['images' => [['url' => 'https://images.example.test/poster.jpg', 'type' => 'invalid-role', 'width' => 1, 'height' => 1, 'orient' => 'P', 'size' => 1]]],
    ];

    expect($service->apply($context, $epg, $snapshot['token'], [$patch])['status'])->toBe('conflict')
        ->and($service->apply($context, $epg, $snapshot['token'], [[...$patch, 'locator' => 'programme:OTk5']])['status'])->toBe('conflict')
        ->and($service->apply($context, $epg, $snapshot['token'], [[...$patch, 'changes' => ['title' => 'Valid']], [...$patch, 'changes' => ['title' => 'Conflicting']]])['status'])->toBe('conflict')
        ->and($service->apply($context, $epg, $snapshot['token'], [[...$patch, 'row_revision' => 'stale', 'changes' => ['title' => 'Valid']]])['status'])->toBe('conflict')
        ->and(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist)))->toBeTrue()
        ->and(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist, true)))->toBeTrue();
});

it('invalidates XMLTV and gzip output for every playlist consuming the EPG', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $firstPlaylist = Playlist::factory()->for($user)->create();
    $secondPlaylist = Playlist::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create();
    Channel::factory()->for($user)->for($firstPlaylist)->create(['epg_channel_id' => $epgChannel->id]);
    Channel::factory()->for($user)->for($secondPlaylist)->create(['epg_channel_id' => $epgChannel->id]);
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    foreach ([$firstPlaylist, $secondPlaylist] as $playlist) {
        Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist), '<tv/>');
        Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist, true), 'gzip');
    }
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);

    expect($service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]])['status'])->toBe('applied');
    foreach ([$firstPlaylist, $secondPlaylist] as $playlist) {
        expect(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist)))->toBeFalse()
            ->and(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist, true)))->toBeFalse();
    }
});

it('serializes an apply with the same per-EPG mutation lock used by generation publishing', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    $resolver = app(EpgCacheGenerationResolver::class);
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $lock = Mockery::mock();
    Cache::shouldReceive('lock')->once()->with($resolver->mutationLockName($epg), 30)->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(10, Mockery::type(Closure::class))->andReturnUsing(function (int $seconds, Closure $callback): array {
        return $callback();
    });

    expect($service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]])['status'])->toBe('applied');
});

it('stages the complete patched generation before acquiring the shared mutation lock', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $source = completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    $resolver = app(EpgCacheGenerationResolver::class);
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $lock = Mockery::mock();

    Cache::shouldReceive('lock')->once()->with($resolver->mutationLockName($epg), 30)->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(10, Mockery::type(Closure::class))->andReturnUsing(function (int $seconds, Closure $callback) use ($epg, $source): array {
        expect(Storage::disk('local')->directories("epg-cache/{$epg->uuid}/v2/generations"))->toHaveCount(2)
            ->and(app(EpgCacheGenerationResolver::class)->resolve($epg))->toBe($source);

        return $callback();
    });

    expect($service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]])['status'])->toBe('applied');
});

it('discards a staged generation when the active generation changes under the mutation lock', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $source = completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    $replacement = completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Concurrent update',
    ]]);
    $resolver = app(EpgCacheGenerationResolver::class);
    $resolver->publish($epg, $source);
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $lock = Mockery::mock();

    Cache::shouldReceive('lock')->once()->with($resolver->mutationLockName($epg), 30)->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(10, Mockery::type(Closure::class))->andReturnUsing(function (int $seconds, Closure $callback) use ($epg, $replacement, $resolver): array {
        $resolver->publishWithinMutationLock($epg, $replacement);

        return $callback();
    });

    expect($service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Plugin update'],
    ]])['status'])->toBe('stale_snapshot')
        ->and($resolver->resolve($epg))->toBe($replacement)
        ->and(Storage::disk('local')->directories("epg-cache/{$epg->uuid}/v2/generations"))->toHaveCount(2);
});

it('does not publish a generation for a no-op patch', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);

    expect($service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Original'],
    ]])['status'])->toBe('noop')
        ->and(app(EpgCacheGenerationResolver::class)->resolve($epg))->toEndWith($snapshot['generation']);
});

it('accepts canonical HTTPS URLs and every supported image role in an enrichment patch', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    completeEnrichmentGeneration($epg, [[
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => 'Original',
    ]]);
    $service = app(EpgCacheEnrichmentService::class);
    $context = enrichmentContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $images = collect(['poster', 'banner', 'fanart', 'logo'])
        ->map(fn (string $type): array => [
            'url' => "https://images.example.test/{$type}.jpg",
            'type' => $type,
            'width' => 100,
            'height' => 200,
            'orient' => 'P',
            'size' => 1000,
        ])
        ->all();

    $result = $service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => [
            'urls' => [['system' => 'TMDB', 'value' => 'https://www.themoviedb.org/tv/1']],
            'images' => $images,
        ],
    ]]);

    expect($result['status'])->toBe('applied');
});
