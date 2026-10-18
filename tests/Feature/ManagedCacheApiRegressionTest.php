<?php

use App\Models\CachedContentActivityLease;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\EmbyLibraryMapping;
use App\Models\Episode;
use App\Models\Group;
use App\Models\ManagedCacheEvent;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use App\Services\ManagedCacheCapacitySnapshot;
use App\Services\ManagedCacheCapacitySnapshotSource;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
beforeEach(function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'cache.default' => 'array']);
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('cache');
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    cache()->put("p:{$this->playlist->id}:xtream_status", []);
    $auth = PlaylistAuth::factory()->for($this->user)->create(['enabled' => true, 'username' => 'emby-companion', 'password' => 'companion-secret', 'library_publishing_enabled' => true]);
    $auth->assignTo($this->playlist);
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly(['type' => 'emby']);
    $this->mapping = EmbyLibraryMapping::factory()->for($this->user)->for($this->integration, 'integration')->create([
        'source_kind' => 'all', 'source_identifier' => '*', 'source_label' => 'All Movies',
        'target_library_name' => 'Managed Movies', 'output_path' => '/fixture/movies', 'is_managed' => true,
        'last_planned_revision' => str_repeat('a', 64), 'last_applied_revision' => str_repeat('a', 64),
    ]);
    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->app_output_enabled = true;
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    $this->channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly(['enabled' => true, 'is_vod' => true, 'container_extension' => 'mkv']);
    $item = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping)['items'][0];
    $this->payload = ['username' => 'emby-companion', 'password' => 'companion-secret', 'api_version' => 1,
        'integration_id' => $this->integration->id, 'mapping_uuid' => $this->mapping->uuid, 'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(), 'consumer_id' => str_repeat('b', 64),
        'targets' => [['canonical_id' => $item['canonical_id'], 'variant_key' => $item['variants'][0]['key']]]];
});

it('refuses new prefetch when applied generation differs from planned', function () {
    $this->mapping->update(['last_planned_revision' => str_repeat('c', 64)]);
    app()->instance(ManagedCacheCapacitySnapshotSource::class, new class implements ManagedCacheCapacitySnapshotSource
    {
        public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot
        {
            return new ManagedCacheCapacitySnapshot('review-account', 3, 1, 0);
        }
    });
    $response = $this->postJson('/player_api.php', [...$this->payload, 'action' => 'm3u_editor_cache_prefetch']);
    fwrite(STDERR, 'MANAGED_PREFETCH_PLANNED_MISMATCH '.json_encode(['http' => $response->status(), 'result' => $response->json('data.results.0.status'), 'file_count' => CachedContentFile::count()]).PHP_EOL);
    $response->assertStatus(409);
    expect(CachedContentFile::count())->toBe(0);
});

it('queues each requested target while an account prefetch slot is already reserved', function () {
    $this->channel->update(['tmdb_id' => 900001]);
    Channel::factory()->for($this->user)->createQuietly([
        'playlist_id' => $this->channel->playlist_id,
        'group_id' => $this->channel->group_id,
        'enabled' => true, 'is_vod' => true, 'container_extension' => 'mkv',
        'tmdb_id' => 900002,
    ]);
    app()->instance(ManagedCacheCapacitySnapshotSource::class, new class implements ManagedCacheCapacitySnapshotSource
    {
        public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot
        {
            return new ManagedCacheCapacitySnapshot('multi-target-account', 3, 1, 0);
        }
    });
    $items = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping)['items'];
    expect($items)->toHaveCount(2);
    $targets = array_map(fn ($item) => [
        'canonical_id' => $item['canonical_id'], 'variant_key' => $item['variants'][0]['key'],
    ], $items);

    $this->postJson('/player_api.php', [
        ...$this->payload, 'action' => 'm3u_editor_cache_prefetch', 'targets' => $targets,
    ])->assertOk()
        ->assertJsonPath('data.results.0.status', 'queued')
        ->assertJsonPath('data.results.1.status', 'queued');

    expect(CachedContentFile::where('managed_by', 'managed_publishing')->count())->toBe(2);
});

it('permits exact old session stop after applied revision changes', function () {
    $session = (string) Str::uuid();
    $start = [...$this->payload, 'action' => 'm3u_editor_cache_playback', 'session_id' => $session, 'sequence' => 1, 'state' => 'start'];
    $this->postJson('/player_api.php', $start)->assertOk();
    $this->mapping->update(['last_applied_revision' => str_repeat('c', 64), 'last_planned_revision' => str_repeat('c', 64)]);
    $this->postJson('/player_api.php', [...$start, 'request_id' => (string) Str::uuid(), 'sequence' => 2, 'state' => 'stop'])->assertOk();
    expect(CachedContentActivityLease::where('lease_id', $session)->sole()->expires_at->isFuture())->toBeFalse();
});

it('keeps heartbeat traffic out of the event journal', function () {
    $session = (string) Str::uuid();
    foreach ([1, 2, 3] as $sequence) {
        $this->postJson('/player_api.php', [...$this->payload, 'action' => 'm3u_editor_cache_playback',
            'request_id' => (string) Str::uuid(), 'session_id' => $session, 'sequence' => $sequence, 'state' => 'heartbeat'])->assertOk();
    }
    expect(ManagedCacheEvent::count())->toBe(0);
});
