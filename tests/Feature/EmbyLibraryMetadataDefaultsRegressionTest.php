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
                'State' => str_ends_with($request->url(), '/Commit') ? 'committed' : 'prepared',
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
    fwrite(STDERR, '\nTRANSPORT '.json_encode([
        'status' => $mapping->status, 'confirmed' => $mapping->managed_library_creation_confirmed_at !== null,
        'applied' => $mapping->library_options_applied_at !== null,
        'create' => $this->createRequests, 'options' => $this->optionsRequests,
    ]).'\n');
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
    fwrite(STDERR, '\nDELAYED '.json_encode([
        'status' => $mapping->status, 'confirmed' => $mapping->managed_library_creation_confirmed_at !== null,
        'applied' => $mapping->library_options_applied_at !== null,
        'create' => $this->createRequests, 'options' => $this->optionsRequests,
    ]).'\n');
    expect($mapping->status === 'planned' && $mapping->library_options_applied_at === null)->toBeFalse();
});

it('review two new publications must never apply options to an unowned preexisting library', function () {
    $this->scenario = 'foreign';
    reviewer1538Publish($this, 0);
    reviewer1538Publish($this, 1);
    fwrite(STDERR, '\nFOREIGN '.json_encode([
        'mappings' => EmbyLibraryMapping::query()->count(), 'create' => $this->createRequests,
        'discovery' => $this->discoveryRequests, 'options' => $this->optionsRequests,
    ]).'\n');
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
    fwrite(STDERR, '\nREUSE '.json_encode([
        'mappings' => EmbyLibraryMapping::query()->count(),
        'create' => $this->createRequests,
        'discovery' => $this->discoveryRequests,
        'options' => $this->optionsRequests,
    ]).'\n');
    expect($this->createRequests)->toBe(1)
        ->and($this->optionsRequests)->toBe(1)
        ->and($this->discoveryRequests)->toBe(1);
});

it('review budget transport recovery uses the preflight discovery once', function () {
    $this->scenario = 'exception';
    reviewer1538Publish($this)->assertHasNoActionErrors();
    $mapping = EmbyLibraryMapping::query()->sole();
    fwrite(STDERR, '\nBUDGET '.json_encode([
        'status' => $mapping->status,
        'discovery' => $this->discoveryRequests,
        'options' => $this->optionsRequests,
        'create' => $this->createRequests,
    ]).'\n');
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
    fwrite(STDERR, '\nRETRY '.json_encode([
        'status' => $mapping->status,
        'initial_discovery' => $beforeDiscovery,
        'retry_discovery' => $this->discoveryRequests - $beforeDiscovery,
        'options' => $this->optionsRequests,
        'create' => $this->createRequests,
    ]).'\n');
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
