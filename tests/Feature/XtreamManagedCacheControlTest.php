<?php

use App\Models\CachedContentActivityLease;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\ManagedCacheEvent;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'cache.default' => 'array',
    ]);
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake(CachedContentFile::DISK);

    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    cache()->put("p:{$this->playlist->id}:xtream_status", []);
    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'library_publishing_enabled' => true,
    ]);
    $this->auth->assignTo($this->playlist);
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly([
        'type' => 'emby',
        'api_key' => 'must-not-leak',
    ]);
    $this->mapping = EmbyLibraryMapping::factory()
        ->for($this->user)
        ->for($this->integration, 'integration')
        ->create([
            'source_kind' => 'all',
            'source_identifier' => '*',
            'source_label' => 'All Movies',
            'target_library_name' => 'Managed Movies',
            'output_path' => '/srv/emby/managed/movies',
            'is_managed' => true,
            'last_planned_revision' => str_repeat('a', 64),
            'last_applied_revision' => str_repeat('a', 64),
        ]);

    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->app_output_enabled = true;
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
});

function managedCacheActionUrl(array $parameters = []): string
{
    return '/player_api.php?'.http_build_query(array_merge([
        'username' => 'emby-companion',
        'password' => 'companion-secret',
    ], $parameters));
}

it('rejects prefetch without required target fields', function () {
    $this->postJson(managedCacheActionUrl([
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
    ]))->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_request');
});

it('returns deferred when managed cache capacity is unknown', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];

    $response = $this->postJson(managedCacheActionUrl([
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ]));

    $response->assertOk()
        ->assertJsonPath('data.request_id', $response->json('data.request_id'))
        ->assertJsonPath('data.results.0.canonical_id', $item['canonical_id'])
        ->assertJsonPath('data.results.0.variant_key', $item['variants'][0]['key'])
        ->assertJsonPath('data.results.0.status', 'deferred');
});

it('accepts the companion form contract with one global consumer id', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];

    $this->post('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ])->assertOk()
        ->assertJsonPath('data.results.0.status', 'deferred');
});

it('records an interest for an existing catalog cache without adopting it', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $file = CachedContentFile::factory()->forItem($channel)->completed()->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'cached');

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ])->assertOk()
        ->assertJsonPath('data.results.0.status', 'already_cached');

    expect(ManagedCacheInterest::query()
        ->where('cached_content_file_id', $file->id)
        ->where('consumer_id', 'profile-a')
        ->exists())->toBeTrue()
        ->and($file->fresh()->managed_by)->toBeNull();
});

it('creates a playback lease from the validated companion session', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];
    $sessionId = (string) Str::uuid();

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_playback',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'session_id' => $sessionId,
        'sequence' => 1,
        'state' => 'start',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ])->assertOk();

    expect(CachedContentActivityLease::query()
        ->where('integration_id', $this->integration->id)
        ->where('lease_id', $sessionId)
        ->where('kind', 'playback')
        ->exists())->toBeTrue();
});

it('returns idempotent success for identical prefetch request id and payload', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];
    $requestId = (string) Str::uuid();
    $payload = [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => $requestId,
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ];

    $first = $this->postJson('/player_api.php', $payload);
    $first->assertOk();

    $second = $this->postJson('/player_api.php', $payload);
    $second->assertOk()
        ->assertJsonPath('data.status', $first->json('data.status'));

    expect(ManagedCacheEvent::count())->toBe(1);
});

it('conflicts when the same request id carries a different payload', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];
    $requestId = (string) Str::uuid();
    $base = [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => $requestId,
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ];

    $this->postJson('/player_api.php', $base)->assertOk();

    $conflict = $base;
    $conflict['consumer_id'] = 'profile-b';

    $this->postJson('/player_api.php', $conflict)
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_conflict');
});
