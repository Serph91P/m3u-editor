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
