<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\EmbyLibraryMappingsRelationManager;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->scenario = 'control';
    $this->failOptions = false;
    $this->inventoryReady = false;
    $this->inventoryRequests = 0;
    $this->createRequests = 0;
    $this->optionsRequests = 0;
    $this->discoveryRequests = 0;
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
    $playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->groups = [
        Group::factory()->for($this->user)->for($playlist)->create(['name' => 'Action', 'type' => 'vod']),
        Group::factory()->for($this->user)->for($playlist)->create(['name' => 'Comedy', 'type' => 'vod']),
    ];
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly([
        'type' => 'emby', 'host' => 'emby.test', 'port' => 8096, 'ssl' => true,
        'api_key' => 'synthetic-review-key', 'emby_publisher_writable_paths' => null,
    ]);
    $this->path = '/config/plugins/m3u-editor/managed-publishing/movies-0123456789abcdef01234567';
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/M3uEditor/Managed/Setup/V1')) {
            return Http::response([
                'CapabilityVersion' => 1, 'IntegrationId' => $this->integration->id,
                'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
                'Ready' => true, 'Result' => 'Ready',
            ]);
        }
        if (str_contains($request->url(), '/M3uEditor/Managed/Libraries/V1/')) {
            return Http::response([
                'CapabilityVersion' => 1, 'IntegrationId' => $this->integration->id,
                'OperationId' => $request->data()['OperationId'], 'PreparedPath' => $this->path,
                'State' => str_ends_with($request->url(), '/Commit')
                    ? 'committed'
                    : (str_ends_with($request->url(), '/Abort') ? 'aborted' : 'prepared'),
                'Success' => true,
            ]);
        }
        if (str_contains($request->url(), '/Libraries/AvailableOptions')) {
            $this->discoveryRequests++;

            return Http::response([
                'MetadataReaders' => [['Name' => 'NFO', 'DefaultEnabled' => true]],
                'DefaultLibraryOptions' => ['DisabledLocalMetadataReaders' => []],
            ]);
        }
        if (str_ends_with($request->url(), '/Library/VirtualFolders/LibraryOptions')) {
            $this->optionsRequests++;

            return Http::response([], $this->failOptions ? 500 : 200);
        }
        if (str_ends_with($request->url(), '/Library/VirtualFolders')) {
            if ($request->method() === 'POST') {
                $this->createRequests++;
                if ($this->scenario === 'exception') {
                    throw new ConnectionException('synthetic transport failure');
                }

                return Http::response([], $this->scenario === 'server500' ? 500 : 204);
            }
            $this->inventoryRequests++;
            $visible = $this->scenario === 'foreign'
                || $this->inventoryReady
                || ($this->scenario !== 'delayed' && $this->inventoryRequests > 1);

            return Http::response($visible ? [[
                'ItemId' => 'review-library', 'Name' => 'Managed Movies',
                'CollectionType' => 'movies', 'Locations' => [$this->path],
            ]] : []);
        }
        throw new RuntimeException('Unexpected synthetic request: '.$request->url());
    });
});

function reviewer1538Publish($test, int $source = 0)
{
    return Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $test->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'publication_type' => 'movies', 'sources' => ['vod:'.$test->groups[$source]->id],
        'destination' => '__new__', 'new_library_name' => 'Managed Movies',
    ]);
}

it('review control applies options for immediate confirmed creation', function (string $scenario) {
    $this->scenario = $scenario;
    reviewer1538Publish($this)->assertHasNoActionErrors();
    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping->status)->toBe('planned')
        ->and($mapping->library_options_applied_at)->not->toBeNull()
        ->and($this->createRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(1);
})->with(['control', 'server500']);

it('review transport recovery must not plan an owned library without applying NFO options', function () {
    $this->scenario = 'exception';
    reviewer1538Publish($this)->assertHasNoActionErrors();
    $mapping = EmbyLibraryMapping::query()->sole();

    expect($mapping->status === 'planned' && $mapping->library_options_applied_at === null)->toBeFalse();
});

it('review delayed stable ID must not plan before options are applied', function () {
    $this->scenario = 'delayed';
    $component = reviewer1538Publish($this)->assertHasNoActionErrors();
    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping->status)->toBe('pending')->and($this->optionsRequests)->toBe(0);
    $this->inventoryReady = true;
    $component->callAction(TestAction::make('reconcile')->table($mapping));
    $mapping->refresh();

    expect($mapping->status === 'planned' && $mapping->library_options_applied_at === null)->toBeFalse();
});

it('review two new publications must never apply options to an unowned preexisting library', function () {
    $this->scenario = 'foreign';
    reviewer1538Publish($this, 0);
    reviewer1538Publish($this, 1);

    expect($this->optionsRequests)->toBe(0)
        ->and($this->discoveryRequests)->toBe(0)
        ->and(EmbyLibraryMapping::query()->count())->toBe(0);
});

it('review once applied options stay applied when another source reuses owned library', function () {
    reviewer1538Publish($this, 0)->assertHasNoActionErrors();
    $first = EmbyLibraryMapping::query()->sole();
    expect($first->managed_library_creation_confirmed_at)->not->toBeNull()
        ->and($first->library_options_applied_at)->not->toBeNull();
    reviewer1538Publish($this, 1)->assertHasNoActionErrors();

    expect($this->createRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(1);
});

it('review budget transport recovery uses the preflight discovery once', function () {
    $this->scenario = 'exception';
    reviewer1538Publish($this)->assertHasNoActionErrors();
    $mapping = EmbyLibraryMapping::query()->sole();

    expect($mapping->library_options_applied_at)->not->toBeNull()
        ->and($this->createRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(1);
});

it('review retry transport recovery keeps ID and ownership after options failure then heals once', function () {
    $this->scenario = 'exception';
    $this->failOptions = true;
    $component = reviewer1538Publish($this)->assertHasNoActionErrors();
    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping->status)->toBe('failed')
        ->and($mapping->target_library_id)->toBe('review-library')
        ->and($mapping->managed_library_creation_confirmed_at)->not->toBeNull()
        ->and($mapping->library_options_applied_at)->toBeNull()
        ->and($mapping->last_planned_revision)->toBeNull()
        ->and($mapping->error_summary)->not->toBeNull()
        ->and(app(EmbyPublicationCatalogService::class)->buildForUser($this->user)['mappings'])->toBe([])
        ->and($this->createRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(1);
    $beforeDiscovery = $this->discoveryRequests;
    $this->failOptions = false;
    $component->callAction(TestAction::make('reconcile')->table($mapping));
    $mapping->refresh();

    expect($mapping->status)->toBe('planned')
        ->and($mapping->target_library_id)->toBe('review-library')
        ->and($mapping->library_options_applied_at)->not->toBeNull()
        ->and($mapping->error_summary)->toBeNull()
        ->and($this->createRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(2)
        ->and($this->discoveryRequests - $beforeDiscovery)->toBe(1);
    $component->callAction(TestAction::make('reconcile')->table($mapping));
    expect($this->createRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(2)
        ->and($this->discoveryRequests - $beforeDiscovery)->toBe(1);
});

it('keeps the library-wide applied state when a second source reuses an owned library', function () {
    reviewer1538Publish($this, 0)->assertHasNoActionErrors();
    $component = reviewer1538Publish($this, 1)->assertHasNoActionErrors();
    $second = EmbyLibraryMapping::query()->latest('id')->firstOrFail();

    expect($second->status)->toBe('planned')
        ->and($second->managed_library_creation_confirmed_at)->not->toBeNull()
        ->and($second->library_options_applied_at)->not->toBeNull()
        ->and(app(EmbyPublicationCatalogService::class)->buildForUser($this->user)['mappings'])->toHaveCount(2)
        ->and($this->createRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(1);

    $component->callAction(TestAction::make('reconcile')->table($second));
    $second->refresh();

    expect($second->status)->toBe('planned')
        ->and($second->library_options_applied_at)->not->toBeNull()
        ->and($this->createRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(1);
});

it('persists a delayed stable ID before managed option discovery and application', function () {
    $this->scenario = 'delayed';
    $component = reviewer1538Publish($this)->assertHasNoActionErrors();
    $mapping = EmbyLibraryMapping::query()->sole();
    $observedIds = [];

    Http::globalRequestMiddleware(function ($request) use ($mapping, &$observedIds) {
        $path = $request->getUri()->getPath();
        if (str_contains($path, '/Libraries/AvailableOptions') || str_contains($path, '/Library/VirtualFolders/LibraryOptions')) {
            $observedIds[] = $mapping->fresh()->target_library_id;
        }

        return $request;
    });

    $this->inventoryReady = true;
    $component->callAction(TestAction::make('reconcile')->table($mapping));
    $mapping->refresh();

    expect($observedIds)->toBe(['review-library', 'review-library'])
        ->and($mapping->status)->toBe('planned')
        ->and($mapping->target_library_id)->toBe('review-library')
        ->and($mapping->library_options_applied_at)->not->toBeNull()
        ->and($this->createRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(2)
        ->and($this->optionsRequests)->toBe(1);
});

it('shares a completed managed-options apply between pending sources that resolve sequentially', function () {
    $this->scenario = 'delayed';
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'publication_type' => 'movies',
        'sources' => ['vod:'.$this->groups[0]->id, 'vod:'.$this->groups[1]->id],
        'destination' => '__new__',
        'new_library_name' => 'Managed Movies',
    ])->assertHasNoActionErrors();
    $mappings = EmbyLibraryMapping::query()->orderBy('id')->get();

    expect($mappings)->toHaveCount(2)
        ->and($mappings->every(fn (EmbyLibraryMapping $mapping): bool => $mapping->status === 'pending'
            && $mapping->managed_library_creation_confirmed_at !== null
            && $mapping->target_library_id === null))->toBeTrue()
        ->and($this->createRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(0);

    $this->inventoryReady = true;
    foreach ($mappings as $mapping) {
        $component->callAction(TestAction::make('reconcile')->table($mapping));
    }

    expect($mappings->every(fn (EmbyLibraryMapping $mapping): bool => $mapping->fresh()->status === 'planned'
        && $mapping->fresh()->target_library_id === 'review-library'
        && $mapping->fresh()->library_options_applied_at !== null))->toBeTrue()
        ->and(app(EmbyPublicationCatalogService::class)->buildForUser($this->user)['mappings'])->toHaveCount(2)
        ->and($this->createRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(2)
        ->and($this->optionsRequests)->toBe(1);
});

it('retains an unowned cleanup intent until a foreign-match abort retry succeeds', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $abortFails = true;
    $abortRequests = 0;
    $operationIds = [];
    Http::fake(function (Request $request) use (&$abortFails, &$abortRequests, &$operationIds) {
        if (str_ends_with($request->url(), '/M3uEditor/Managed/Setup/V1')) {
            return Http::response([
                'CapabilityVersion' => 1,
                'IntegrationId' => $this->integration->id,
                'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
                'Ready' => true,
                'Result' => 'Ready',
            ]);
        }
        if (str_contains($request->url(), '/M3uEditor/Managed/Libraries/V1/')) {
            $operationIds[] = $request->data()['OperationId'];
            $aborting = str_ends_with($request->url(), '/Abort');
            if ($aborting) {
                $abortRequests++;
                if ($abortFails) {
                    return Http::response([], 500);
                }
            }

            return Http::response([
                'CapabilityVersion' => 1,
                'IntegrationId' => $this->integration->id,
                'OperationId' => $request->data()['OperationId'],
                'PreparedPath' => $this->path,
                'State' => $aborting ? 'aborted' : 'prepared',
                'Success' => true,
            ]);
        }
        if (str_ends_with($request->url(), '/Library/VirtualFolders') && $request->method() === 'GET') {
            return Http::response([[
                'ItemId' => 'foreign-library',
                'Name' => 'Managed Movies',
                'CollectionType' => 'movies',
                'Locations' => [$this->path],
            ]]);
        }

        throw new RuntimeException('Unexpected synthetic request.');
    });

    reviewer1538Publish($this, 0)->assertHasActionErrors();
    $intent = EmbyLibraryMapping::query()->sole();
    expect($intent->status)->toBe('cleanup_pending')
        ->and($intent->target_library_id)->toBeNull()
        ->and($intent->managed_library_creation_confirmed_at)->toBeNull()
        ->and($intent->library_options_applied_at)->toBeNull()
        ->and($abortRequests)->toBe(1);

    reviewer1538Publish($this, 1)->assertHasActionErrors();
    expect(EmbyLibraryMapping::query()->sole()->is($intent))->toBeTrue()
        ->and($abortRequests)->toBe(1);

    $abortFails = false;
    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $this->integration->fresh(),
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('reconcile')->table($intent->fresh()));

    expect(EmbyLibraryMapping::query()->count())->toBe(0)
        ->and($abortRequests)->toBe(2)
        ->and(array_unique($operationIds))->toHaveCount(1);
});
