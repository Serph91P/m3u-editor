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

function mutationLockContext(User $user): PluginExecutionContext
{
    $plugin = Plugin::query()->create([
        'plugin_id' => 'epg-enrichment-lock-'.fake()->uuid(),
        'name' => 'EPG Enrichment Lock Fixture',
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
    ]);
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

function completeMutationLockGeneration(Epg $epg, string $title): string
{
    $resolver = app(EpgCacheGenerationResolver::class);
    $directory = $resolver->createGenerationDirectory($epg);
    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path("{$directory}/programmes.sqlite"));
    $store->insert('channel.one', '2026-09-16', Carbon::parse('2026-09-16T01:00:00.000000Z')->getTimestamp(), null, [
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => '2026-09-16T01:00:00.000000Z',
        'title' => $title,
    ]);
    $store->finish();
    Storage::disk('local')->put("{$directory}/metadata.json", json_encode(['cache_version' => 'v2'], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put("{$directory}/channels.json", '{}');
    $resolver->publish($epg, $directory);

    return $directory;
}

it('validates the request patch before entering the shared mutation lock', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    completeMutationLockGeneration($epg, 'Original');
    $resolver = app(EpgCacheGenerationResolver::class);
    $service = new class($resolver) extends EpgCacheEnrichmentService
    {
        public bool $insideMutationLock = false;

        /** @var list<bool> */
        public array $validationLockStates = [];

        protected function validatePatch(array $changes): ?array
        {
            $this->validationLockStates[] = $this->insideMutationLock;

            return parent::validatePatch($changes);
        }
    };
    $context = mutationLockContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $lock = Mockery::mock();
    Cache::shouldReceive('lock')->once()->with($resolver->mutationLockName($epg), 30)->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(10, Mockery::type(Closure::class))->andReturnUsing(function (int $seconds, Closure $callback) use ($service): array {
        $service->insideMutationLock = true;

        try {
            return $callback();
        } finally {
            $service->insideMutationLock = false;
        }
    });

    expect($service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]])['status'])->toBe('applied')
        ->and($service->validationLockStates)->toBe([false]);
});

it('cleans a staged generation and preserves playlist outputs after a lock conflict', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create();
    Channel::factory()->for($user)->for($playlist)->create(['epg_channel_id' => $epgChannel->id]);
    $source = completeMutationLockGeneration($epg, 'Original');
    $replacement = completeMutationLockGeneration($epg, 'Concurrent update');
    $resolver = app(EpgCacheGenerationResolver::class);
    $resolver->publish($epg, $source);
    Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist), '<tv/>');
    Storage::disk('local')->put(EpgCacheService::getPlaylistEpgCachePath($playlist, true), 'gzip');
    $service = app(EpgCacheEnrichmentService::class);
    $context = mutationLockContext($user);
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
        ->and(Storage::disk('local')->directories("epg-cache/{$epg->uuid}/v2/generations"))->toHaveCount(2)
        ->and(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist)))->toBeTrue()
        ->and(Storage::disk('local')->exists(EpgCacheService::getPlaylistEpgCachePath($playlist, true)))->toBeTrue();
});
