<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\EmbyLibraryMappingsRelationManager;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Invokes the relation manager's private sourceLabelOptions() directly, since
 * it's not reachable through a public API and the "Mapped group" field's
 * live-updating options aren't easily assertable through Livewire's action
 * testing helpers.
 */
function embyMappedGroupOptions($component, ?string $sourceKind, ?string $sourceIdentifier, ?string $collectionType): array
{
    $instance = $component->instance();
    $method = new ReflectionMethod($instance, 'sourceLabelOptions');
    $method->setAccessible(true);

    return $method->invoke($instance, $sourceKind, $sourceIdentifier, $collectionType);
}

/** Invokes the relation manager's private sourceSearchOptions() directly. */
function embySourceSearchOptions($component, ?string $sourceKind, string $search, ?string $onlyIdentifier = null): array
{
    $instance = $component->instance();
    $method = new ReflectionMethod($instance, 'sourceSearchOptions');
    $method->setAccessible(true);

    return $method->invoke($instance, $sourceKind, $search, $onlyIdentifier);
}

/** Invokes the relation manager's unified source search directly. */
function embySimpleSourceSearchOptions($component, string $search, ?string $onlySource = null): array
{
    $instance = $component->instance();
    $method = new ReflectionMethod($instance, 'simpleSourceSearchOptions');
    $method->setAccessible(true);

    return $method->invoke($instance, $search, $onlySource);
}

/** Invokes the relation manager's private compatibleLibraryPathOptions() directly. */
function embyCompatibleLibraryPathOptions($component, ?string $libraryId): array
{
    $instance = $component->instance();
    $method = new ReflectionMethod($instance, 'compatibleLibraryPathOptions');
    $method->setAccessible(true);

    return $method->invoke($instance, $libraryId);
}

it('shows managed library mappings only on authorized Emby integrations', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $emby = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $jellyfin = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'jellyfin']);
    $foreignEmby = MediaServerIntegration::factory()->createQuietly(['type' => 'emby']);

    expect(EmbyLibraryMappingsRelationManager::canViewForRecord($emby, EditMediaServerIntegration::class))->toBeTrue()
        ->and(EmbyLibraryMappingsRelationManager::canViewForRecord($jellyfin, EditMediaServerIntegration::class))->toBeFalse()
        ->and(EmbyLibraryMappingsRelationManager::canViewForRecord($foreignEmby, EditMediaServerIntegration::class))->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(EmbyLibraryMappingsRelationManager::canViewForRecord($foreignEmby, EditMediaServerIntegration::class))->toBeTrue();
});

it('renders only the simple managed publishing controls in the create modal', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->assertMountedActionModalSee('What do you want to publish?')
        ->assertMountedActionModalSee('Create a new library')
        ->assertMountedActionModalSee('Publish to Emby')
        ->assertMountedActionModalDontSee('Source type')
        ->assertMountedActionModalDontSee('Mapped group')
        ->assertMountedActionModalDontSee('Output path')
        ->assertMountedActionModalDontSee('Managed')
        ->assertMountedActionModalDontSee('Publishing options')
        ->assertMountedActionModalDontSee('Naming')
        ->assertMountedActionModalDontSee('Cleanup')
        ->assertMountedActionModalDontSee('Publish local NFO')
        ->assertMountedActionModalDontSee('Publish visible versions')
        ->assertMountedActionModalDontSee('Refresh Emby after successful sync');
});

it('publishes a first-time movie source to a new Emby library with safe derived defaults', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Action',
        'type' => 'vod',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => null,
    ]);
    $managedPath = '/config/plugins/m3u-editor/managed-publishing/managed-movies';
    $baselineTransactionLevel = DB::transactionLevel();
    $transactionLevels = [];
    $libraryRequestCount = 0;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($integration, $managedPath, &$libraryRequestCount, &$transactionLevels) {
        $transactionLevels[] = DB::transactionLevel();

        if (str_ends_with($request->url(), '/M3uEditor/Managed/Setup/V1')) {
            return Http::response([
                'CapabilityVersion' => 1,
                'IntegrationId' => $integration->id,
                'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
                'Ready' => true,
                'Result' => 'Ready',
            ]);
        }

        $libraryRequestCount++;

        return match ($libraryRequestCount) {
            1 => Http::response([]),
            2 => Http::response([], 204),
            default => Http::response([[
                'ItemId' => 'managed-library-1',
                'Name' => 'Managed Movies',
                'CollectionType' => 'movies',
                'Locations' => [$managedPath],
            ]]),
        };
    });

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => '__new__',
        'new_library_name' => 'Managed Movies',
    ])->assertHasNoActionErrors()
        ->assertNotified();

    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping)
        ->source_kind->toBe('vod_group')
        ->source_identifier->toBe((string) $group->id)
        ->source_label->toBe('Action')
        ->collection_type->toBe('movies')
        ->target_library_id->toBe('managed-library-1')
        ->target_library_name->toBe('Managed Movies')
        ->output_path->toBe($managedPath)
        ->is_managed->toBeTrue()
        ->options->toBe([
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ])
        ->status->toBe('planned')
        ->and($mapping->last_planned_revision)->toBeString()->not->toBeEmpty()
        ->and($transactionLevels)->each->toBe($baselineTransactionLevel);
});

it('offers only source-compatible libraries with a companion-approved writable path', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Action',
        'type' => 'vod',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_managed_setup_root' => '/srv/emby/managed',
        'emby_publisher_writable_paths' => ['/srv/emby/legacy'],
        'available_libraries' => [
            [
                'id' => 'compatible-movies',
                'name' => 'Compatible Movies',
                'type' => 'movies',
                'paths' => ['/srv/emby/managed/movies'],
            ],
            [
                'id' => 'legacy-movies',
                'name' => 'Legacy Movies',
                'type' => 'movies',
                'paths' => ['/srv/emby/legacy/movies'],
            ],
            [
                'id' => 'unwritable-movies',
                'name' => 'Unwritable Movies',
                'type' => 'movies',
                'paths' => ['/srv/emby/private/movies'],
            ],
            [
                'id' => 'compatible-tv',
                'name' => 'Compatible TV',
                'type' => 'tvshows',
                'paths' => ['/srv/emby/managed/tv'],
            ],
        ],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->set('mountedActions.0.data.source', 'vod:'.$group->id)
        ->assertMountedActionModalSee('Compatible Movies')
        ->assertMountedActionModalSee('Legacy Movies')
        ->assertMountedActionModalDontSee('Unwritable Movies')
        ->assertMountedActionModalDontSee('Compatible TV');
});

it('shows one actionable version error and persists nothing when managed setup is unavailable', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Action',
        'type' => 'vod',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => null,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([], 404),
    ]);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => '__new__',
        'new_library_name' => 'Managed Movies',
    ]);

    $errorBag = $component->instance()->getErrorBag();
    $errors = $errorBag->all();
    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('managed setup version 1', 'retry')
        ->not->toContain('emby-secret', '/config/', 'integration ID', 'output path')
        ->and(EmbyLibraryMapping::query()->count())->toBe(0)
        ->and($integration->refresh())
        ->emby_managed_setup_binding_id->toBeNull()
        ->emby_managed_setup_root->toBeNull();
});

it('shows an accurately named Custom Playlist nested group only under Advanced', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly(['name' => 'Favorites']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_managed_setup_root' => '/srv/emby/managed',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'movie-library',
            'name' => 'Movie Library',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/movies'],
        ]],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->set('mountedActions.0.data.source', 'custom_playlist:'.$customPlaylist->id)
        ->set('mountedActions.0.data.destination', 'movie-library')
        ->assertMountedActionModalSee('Advanced options')
        ->assertMountedActionModalSee('VOD group within Custom Playlist')
        ->assertMountedActionModalDontSee('Mapped group')
        ->assertMountedActionModalDontSee('Series category within Custom Playlist');
});

it('asks for a new library type only when the selected source cannot infer it', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['type' => 'vod']);
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->set('mountedActions.0.data.source', 'vod:'.$group->id)
        ->set('mountedActions.0.data.destination', '__new__')
        ->assertMountedActionModalDontSee('Library type');

    $component->set('mountedActions.0.data.source', 'custom_playlist:'.$customPlaylist->id)
        ->set('mountedActions.0.data.destination', '__new__')
        ->assertMountedActionModalSee('Library type');
});

it('publishes to an existing compatible library using only source and destination choices', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Action',
        'type' => 'vod',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_managed_setup_root' => '/srv/emby/managed',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'existing-library',
            'name' => 'Existing Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/movies'],
        ]],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/srv/emby/managed',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => 'existing-library',
        'source_kind' => 'all',
        'source_label' => 'Spoofed label',
        'collection_type' => 'tvshows',
        'output_path' => '/browser/supplied/path',
        'is_managed' => true,
    ])->assertHasNoActionErrors();

    $mapping = EmbyLibraryMapping::query()->sole();

    expect($mapping)
        ->source_kind->toBe('vod_group')
        ->source_identifier->toBe((string) $group->id)
        ->source_label->toBe('Action')
        ->target_library_id->toBe('existing-library')
        ->target_library_name->toBe('Existing Movies')
        ->collection_type->toBe('movies')
        ->output_path->toBe('/srv/emby/managed/movies')
        ->is_managed->toBeFalse()
        ->and(app(EmbyPublicationCatalogService::class)->buildMapping($mapping)['target_library']['managed'])
        ->toBeTrue()
        ->and($integration->refresh()->getImportLibraryIdsForType('movies'))
        ->toBe([]);
});

it('keeps confirmed setup while rolling back mapping state when Emby rejects library creation', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Action',
        'type' => 'vod',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => null,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
        'https://emby.test:8096/Library/VirtualFolders' => Http::sequence()
            ->push([], 200)
            ->push([], 401)
            ->push([], 401),
    ]);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => '__new__',
        'new_library_name' => 'Managed Movies',
    ]);

    $errors = $component->instance()->getErrorBag()->all();
    expect($errors)->toHaveCount(1)
        ->and($errors[0])->not->toContain(
            'emby-secret',
            '/config/plugins/m3u-editor/managed-publishing',
        )
        ->and(EmbyLibraryMapping::query()->count())->toBe(0)
        ->and($integration->refresh())
        ->emby_managed_setup_binding_id->toBe($integration->id)
        ->emby_managed_setup_root->toBe('/config/plugins/m3u-editor/managed-publishing')
        ->emby_publisher_writable_paths->toBeNull()
        ->and($integration->getEmbyPublisherWritablePaths())
        ->toBe(['/config/plugins/m3u-editor/managed-publishing']);
});

it('creates an owned mapping from eligible unified source and destination choices', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Action', 'type' => 'vod']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_managed_setup_root' => '/srv/emby/managed',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'existing-library',
            'name' => 'Managed Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/movies'],
        ]],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/srv/emby/managed',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => 'existing-library',
    ])->assertHasNoActionErrors();

    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping->user_id)->toBe($user->id)
        ->and($mapping->media_server_integration_id)->toBe($integration->id)
        ->and($mapping->source_identifier)->toBe((string) $group->id)
        ->and($mapping->output_path)->toBe('/srv/emby/managed/movies');
});

it('publishes to a companion-confirmed existing library on first setup', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Action', 'type' => 'vod']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_managed_setup_root' => null,
        'emby_publisher_writable_paths' => null,
        'available_libraries' => [[
            'id' => 'existing-library',
            'name' => 'Managed Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/movies'],
        ]],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/srv/emby/managed',
            'Ready' => true,
        ]),
    ]);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);
    $method = new ReflectionMethod($component->instance(), 'simpleLibraryOptions');
    $method->setAccessible(true);

    expect($method->invoke($component->instance(), 'vod:'.$group->id))->toHaveKey(
        'existing-library',
        'Managed Movies',
    );

    $component->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => 'existing-library',
    ])
        ->assertHasNoActionErrors();

    expect(EmbyLibraryMapping::query()->sole())
        ->target_library_id->toBe('existing-library')
        ->output_path->toBe('/srv/emby/managed/movies')
        ->is_managed->toBeFalse();
});

it('derives compatible existing-library destinations from Emby paths and companion roots', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed', 'C:\\Emby\\Managed'],
        'available_libraries' => [
            [
                'id' => 'single',
                'name' => 'Single',
                'type' => 'movies',
                'paths' => ['/srv/emby/managed/movies', '/srv/emby/private'],
            ],
            [
                'id' => 'multiple',
                'name' => 'Multiple',
                'type' => 'tvshows',
                'paths' => ['/srv/emby/managed', 'c:/emby/managed/tv'],
            ],
            [
                'id' => 'none',
                'name' => 'None',
                'type' => 'movies',
                'paths' => ['/srv/emby/private', '/srv/emby/managed2'],
            ],
        ],
    ]);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    expect(embyCompatibleLibraryPathOptions($component, 'single'))->toBe([
        '/srv/emby/managed/movies' => '/srv/emby/managed/movies',
    ])->and(embyCompatibleLibraryPathOptions($component, 'multiple'))->toBe([
        '/srv/emby/managed' => '/srv/emby/managed',
        'c:/emby/managed/tv' => 'c:/emby/managed/tv',
    ])->and(embyCompatibleLibraryPathOptions($component, 'none'))->toBe([]);
});

it('does not offer an existing library without a companion-approved writable path', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['type' => 'vod']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_managed_setup_root' => '/srv/emby/managed',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'library-1',
            'name' => 'Existing Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/private'],
        ]],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->set('mountedActions.0.data.source', 'vod:'.$group->id)
        ->assertMountedActionModalDontSee('Existing Movies')
        ->assertMountedActionModalDontSee('Compatible library path')
        ->assertMountedActionModalDontSee('Register a writable root')
        ->assertMountedActionModalSee('Create a new library');
});

it('keeps existing-library path selection server-side when libraries have one or several paths', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['type' => 'vod']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_managed_setup_root' => '/srv/emby/managed',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [
            [
                'id' => 'single',
                'name' => 'Single',
                'type' => 'movies',
                'paths' => ['/srv/emby/managed/movies'],
            ],
            [
                'id' => 'multiple',
                'name' => 'Multiple',
                'type' => 'movies',
                'paths' => ['/srv/emby/managed/movies', '/srv/emby/managed/movies-4k'],
            ],
        ],
    ]);
    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->set('mountedActions.0.data.source', 'vod:'.$group->id)
        ->assertMountedActionModalSee('Single')
        ->assertMountedActionModalSee('Multiple')
        ->assertMountedActionModalDontSee('Compatible library path')
        ->assertMountedActionModalDontSee('/srv/emby/managed/movies-4k');
});

it('derives existing library values instead of accepting redundant submitted fields', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_managed_setup_root' => '/srv/emby/managed',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'library-1',
            'name' => 'Existing Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/movies'],
        ]],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/srv/emby/managed',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
    ]);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'all:movies',
        'destination' => 'library-1',
        'source_kind' => 'all',
        'target_library_name' => 'Spoofed name',
        'collection_type' => 'tvshows',
        'output_path' => '/browser/path',
        'is_managed' => true,
    ])->assertHasNoActionErrors();

    expect(EmbyLibraryMapping::query()->sole())
        ->target_library_name->toBe('Existing Movies')
        ->collection_type->toBe('movies')
        ->is_managed->toBeFalse();
});

it('creates a managed library from only its required destination choices', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => null,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/srv/emby/managed',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
        'https://emby.test:8096/Library/VirtualFolders' => Http::sequence()
            ->push([], 200)
            ->push([], 204)
            ->push([[
                'ItemId' => 'managed-library',
                'Name' => 'Managed Movies',
                'CollectionType' => 'movies',
                'Locations' => ['/srv/emby/managed/managed-movies'],
            ]], 200),
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'all:movies',
        'destination' => '__new__',
        'new_library_name' => 'Managed Movies',
        'target_library_id' => 'spoofed-library',
        'output_path' => '/browser/path',
        'is_managed' => false,
    ])->assertHasNoActionErrors();

    expect(EmbyLibraryMapping::query()->sole())
        ->target_library_id->toBe('managed-library')
        ->output_path->toBe('/srv/emby/managed/managed-movies')
        ->is_managed->toBeTrue();
});

it('uses a safe stable directory when a library name has no ASCII slug', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);
    $method = new ReflectionMethod($component->instance(), 'managedLibraryPath');
    $method->setAccessible(true);

    $path = $method->invoke($component->instance(), '/srv/emby/managed', '日本語');

    expect($path)->toStartWith('/srv/emby/managed/library-')
        ->not->toBe('/srv/emby/managed/');
});

it('rejects a duplicate publication before contacting the companion or Emby', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Action', 'type' => 'vod']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
    ]);
    EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => 'Action',
        'collection_type' => 'movies',
    ]);
    Http::preventStrayRequests();
    Http::fake();

    expect(EmbyLibraryMapping::query()
        ->where('media_server_integration_id', $integration->id)
        ->where('source_kind', 'vod_group')
        ->where('source_identifier', (string) $group->id)
        ->where('source_label', 'Action')
        ->where('collection_type', 'movies')
        ->exists())->toBeTrue();

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => '__new__',
        'new_library_name' => 'Duplicate Movies',
    ])->assertHasActionErrors();

    Http::assertNothingSent();
    expect(EmbyLibraryMapping::query()->count())->toBe(1);
});

it('returns an actionable error when another publish is already running', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Action', 'type' => 'vod']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $lock = Cache::lock("emby-publish:{$integration->id}", 900);
    $lock->acquire();
    Http::preventStrayRequests();
    Http::fake();

    try {
        $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
            'ownerRecord' => $integration,
            'pageClass' => EditMediaServerIntegration::class,
        ])->callAction(TestAction::make('create')->table(), [
            'source' => 'vod:'.$group->id,
            'destination' => '__new__',
            'new_library_name' => 'Managed Movies',
        ])->assertHasActionErrors();

        expect($component->instance()->getErrorBag()->all())->toContain(
            'Another Emby publication is already in progress. Retry when it finishes.',
        );
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
    expect(EmbyLibraryMapping::query()->count())->toBe(0);
});

it('registers companion writable paths before creating the first owned mapping', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Action',
        'type' => 'vod',
    ]);
    $auth = PlaylistAuth::factory()->for($user)->create([
        'enabled' => true,
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'library_publishing_enabled' => true,
    ]);
    $auth->assignTo($playlist);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => null,
    ]);

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_register_publisher',
        'api_version' => 1,
        'integration_id' => $integration->id,
        'writable_paths' => ['/srv/emby/managed/movies'],
    ])->assertOk()
        ->assertJsonPath('data.integration_id', $integration->id)
        ->assertJsonPath('data.writable_paths', ['/srv/emby/managed/movies']);

    expect($integration->refresh()->emby_publisher_writable_paths)
        ->toBe(['/srv/emby/managed/movies'])
        ->and($integration->emby_publisher_capabilities_updated_at)->not->toBeNull();

    $integration->update([
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'available_libraries' => [[
            'id' => 'existing-library',
            'name' => 'Managed Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/movies'],
        ]],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/srv/emby/managed/movies',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$group->id,
        'destination' => 'existing-library',
    ])->assertHasNoActionErrors();

    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping->user_id)->toBe($user->id)
        ->and($mapping->media_server_integration_id)->toBe($integration->id)
        ->and($mapping->output_path)->toBe('/srv/emby/managed/movies');
});

it('rejects foreign sources and unadvertised output paths', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $otherUser = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();
    $foreignGroup = Group::factory()->for($otherUser)->for($otherPlaylist)->create([
        'name' => 'Foreign',
        'type' => 'vod',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => null,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $integration->id,
            'ConfirmedRoot' => '/srv/emby/managed',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
    ]);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'source' => 'vod:'.$foreignGroup->id,
        'destination' => '__new__',
        'new_library_name' => 'Managed Movies',
        'source_kind' => 'vod_group',
        'source_label' => 'Foreign',
        'output_path' => '/unadvertised/path',
    ]);

    expect($component->instance()->getErrorBag()->all())->toHaveCount(1)
        ->and(EmbyLibraryMapping::query()->count())->toBe(0);
});

it('shows mapping state and toggles publishing without deleting state', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'output_path' => '/srv/emby/managed/movies',
        'status' => 'failed',
        'error_summary' => 'Redacted failure',
        'enabled' => true,
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->assertCanSeeTableRecords([$mapping])
        ->assertSee('Redacted failure')
        ->assertTableActionExists('preview')
        ->assertTableActionExists('reconcile')
        ->assertTableActionExists('edit')
        ->assertTableActionExists('delete')
        ->call('updateTableColumnState', 'enabled', (string) $mapping->id, false);

    expect($mapping->refresh()->enabled)->toBeFalse()
        ->and($mapping->exists)->toBeTrue();
});

it('previews the exact canonical dry-run plan without mutating the mapping', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'last_planned_revision' => null,
    ]);
    $plan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->assertTableActionExists(
        'preview',
        fn (FilamentAction $action): bool => str_contains(
            (string) $action->getModalContent(),
            $plan['revision'],
        ),
        $mapping,
    );

    expect($mapping->refresh()->last_planned_revision)->toBeNull();
});

it('defers reconcile with a generic pending result while the managed library is unresolved', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'last_planned_revision' => 'unsafe-revision',
    ]);
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push([], 204)
        ->push([], 200);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    $mapping->refresh();
    expect($mapping->target_library_id)->toBeNull()
        ->and($mapping->status)->toBe('pending')
        ->and($mapping->status_summary)->toBe('Pending')
        ->and($mapping->error_summary)->toBeNull()
        ->and($mapping->last_planned_revision)->toBeNull()
        ->and($mapping->status_summary.$mapping->error_summary)
        ->not->toContain('emby-secret', '/srv/emby/managed/movies');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('resolves a pending managed library from a later exact listing without duplicate state', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'last_planned_revision' => null,
    ]);
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push([], 204)
        ->push([], 200)
        ->push([[
            'ItemId' => 'managed-library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies'],
        ]], 200);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    expect($mapping->refresh()->status)->toBe('pending')
        ->and($mapping->target_library_id)->toBeNull();

    $component->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    $mapping->refresh();
    $currentPlan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    expect($mapping->target_library_id)->toBe('managed-library-1')
        ->and($mapping->status)->toBe('planned')
        ->and($mapping->last_planned_revision)->toBe($currentPlan['revision'])
        ->and(EmbyLibraryMapping::query()->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('creates a managed Emby library and plans a bounded manual reconcile', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'last_planned_revision' => null,
    ]);
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push([], 204)
        ->push([[
            'ItemId' => 'managed-library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies'],
        ]], 200);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    $mapping->refresh();
    $currentPlan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    expect($mapping->target_library_id)->toBe('managed-library-1')
        ->and($mapping->status)->toBe('planned')
        ->and($mapping->last_planned_revision)->toBe($currentPlan['revision'])
        ->and($mapping->last_applied_revision)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('edits and deletes an owned mapping through Filament actions', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
    ]);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('edit')->table($mapping), [
        'enabled' => true,
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Renamed Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'options' => [
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ],
    ])->assertHasNoActionErrors();

    expect($mapping->refresh()->target_library_name)->toBe('Renamed Managed Movies');

    $component->callAction(TestAction::make('delete')->table($mapping));
    expect(EmbyLibraryMapping::find($mapping->id))->toBeNull();
});

it('preserves an unavailable existing target on edit and requires a new choice', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'missing-library',
        'target_library_name' => 'Existing Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => false,
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('edit')->table($mapping))
        ->assertSet('mountedActions.0.data.destination_mode', 'existing')
        ->assertSet('mountedActions.0.data.target_library_id', 'missing-library')
        ->callMountedAction()
        ->assertHasActionErrors(['target_library_id']);

    expect($mapping->refresh())
        ->target_library_id->toBe('missing-library')
        ->output_path->toBe('/srv/emby/managed/movies');
});

it('does not silently replace a missing saved path when editing an existing target', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'library-1',
            'name' => 'Existing Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/new-path'],
        ]],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'library-1',
        'target_library_name' => 'Existing Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/old-path',
        'is_managed' => false,
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('edit')->table($mapping), [
        'destination_mode' => 'existing',
        'enabled' => true,
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'library-1',
        'output_path' => '/srv/emby/managed/old-path',
        'options' => $mapping->options,
    ])->assertHasActionErrors();

    expect($mapping->refresh()->output_path)->toBe('/srv/emby/managed/old-path');
});

it('returns no Mapped group options for a live-only custom playlist, for either library type', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly();

    // Live channels only (is_vod: false, no series attached) — nothing here
    // is eligible content for Emby publishing (movies/tvshows only).
    $liveChannel = Channel::factory()->for($user)->for($playlist)->createQuietly([
        'group' => 'PPV', 'is_vod' => false, 'enabled' => true,
    ]);
    $customPlaylist->channels()->attach([$liveChannel->id]);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    expect(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'movies'))->toBe([])
        ->and(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'tvshows'))->toBe([]);
});

it('scopes Mapped group options to VOD groups for movies and series categories for tvshows independently', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly();

    $vodChannel = Channel::factory()->for($user)->for($playlist)->createQuietly([
        'group' => 'Action', 'is_vod' => true, 'enabled' => true,
    ]);
    $customPlaylist->channels()->attach([$vodChannel->id]);

    $category = Category::factory()->for($user)->createQuietly(['name' => 'Drama']);
    $series = Series::factory()->for($user)->for($category)->createQuietly(['enabled' => true]);
    $customPlaylist->series()->attach([$series->id]);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    expect(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'movies'))
        ->toBe(['Action' => 'Action'])
        ->and(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'tvshows'))
        ->toBe(['Drama' => 'Drama']);
});

it('clears stale Custom Playlist nested state when the new library type changes', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly(['name' => 'Sports']);
    $vodChannel = Channel::factory()->for($user)->for($playlist)->createQuietly([
        'group' => 'Action', 'is_vod' => true, 'enabled' => true,
    ]);
    $customPlaylist->channels()->attach([$vodChannel->id]);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table());

    $component->set('mountedActions.0.data.source', 'custom_playlist:'.$customPlaylist->id)
        ->set('mountedActions.0.data.destination', '__new__')
        ->set('mountedActions.0.data.new_library_type', 'movies')
        ->assertSet('mountedActions.0.data.custom_playlist_selection', null)
        ->set('mountedActions.0.data.custom_playlist_selection', 'Action')
        ->set('mountedActions.0.data.new_library_type', 'tvshows')
        ->assertSet('mountedActions.0.data.custom_playlist_selection', null);
});

it('disambiguates same-named VOD groups across playlists in the Source search, without leaking the suffix into Mapped group', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlistA = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider A']);
    $playlistB = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider B']);
    $groupA = Group::factory()->for($user)->for($playlistA)->create(['name' => 'Action', 'type' => 'vod']);
    $groupB = Group::factory()->for($user)->for($playlistB)->create(['name' => 'Action', 'type' => 'vod']);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    $results = embySourceSearchOptions($component, 'vod_group', 'action');
    expect($results)->toBe([
        (string) $groupA->id => 'Action (Provider A)',
        (string) $groupB->id => 'Action (Provider B)',
    ]);

    // The already-selected-value lookup (getOptionLabelUsing) resolves the same way.
    expect(embySourceSearchOptions($component, 'vod_group', '', (string) $groupB->id))
        ->toBe([(string) $groupB->id => 'Action (Provider B)']);

    expect(embySimpleSourceSearchOptions($component, 'action'))->toBe([
        'vod:'.$groupA->id => 'Movies: Action (Provider A)',
        'vod:'.$groupB->id => 'Movies: Action (Provider B)',
    ]);

    // Mapped group's auto-populated value must stay the raw group name — it's
    // matched verbatim against channels.group by EmbyPublicationCatalogService,
    // so the "(Provider B)" UI disambiguation must never leak into it.
    $action = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table());

    $action->set('mountedActions.0.data.source', 'vod:'.$groupB->id)
        ->assertSet('mountedActions.0.data.source', 'vod:'.$groupB->id)
        ->assertSet('mountedActions.0.data.source_label', null);
});

it('caps the number of items rendered in the Preview modal without affecting the actual revision hash', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'collection_type' => 'movies',
        'target_library_id' => null,
    ]);

    // More than the 50-item preview cap.
    Channel::factory()->for($user)->for($playlist)->count(60)->createQuietly([
        'is_vod' => true, 'enabled' => true,
    ]);

    $fullPlan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    expect($fullPlan['items'])->toHaveCount(60);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->assertTableActionExists('preview', function (FilamentAction $action) use ($fullPlan): bool {
        $modalHtml = (string) $action->getModalContent();

        // The rendered JSON only carries 50 items...
        $renderedItemCount = substr_count($modalHtml, '&quot;canonical_id&quot;');

        // ...but the revision shown is still the hash of the complete, untruncated catalog.
        return $renderedItemCount === 50
            && str_contains($modalHtml, $fullPlan['revision']);
    }, $mapping);
});
