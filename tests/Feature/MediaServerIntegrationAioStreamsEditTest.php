<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\MoviesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\SeriesRelationManager;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

it('shows the AIOStreams browse tab for aiostreams integrations, enabled or not', function () {
    $enabled = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);
    $disabled = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams Disabled',
        'type' => 'aiostreams',
        'enabled' => false,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    expect(AioStreamsRelationManager::canViewForRecord($enabled, EditMediaServerIntegration::class))->toBeTrue();
    expect(AioStreamsRelationManager::canViewForRecord($disabled, EditMediaServerIntegration::class))->toBeTrue();
});

it('hides the AIOStreams browse tab for non-aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test Jellyfin',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'api_key' => 'secret',
        'enabled' => true,
    ]);

    expect(AioStreamsRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeFalse();
});

it('hides the movies and series relation managers for aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    expect(MoviesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeFalse();
    expect(SeriesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeFalse();
});

it('still shows the movies and series relation managers for non-aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test Jellyfin',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'api_key' => 'secret',
        'enabled' => true,
    ]);

    expect(MoviesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeTrue();
    expect(SeriesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeTrue();
});

it('renders the edit page for an aiostreams integration without error', function () {
    // Regression: AioStreamsRelationManager is a "fake" relation manager (no real
    // Eloquent relationship) used purely to embed AioStreamsBrowse inline on this
    // page. Filament's InteractsWithRelationshipTable::bootedInteractsWithTable()
    // still eagerly builds a full table config for every relation manager regardless
    // of whether it's ever rendered, and several of its derived label lookups throw
    // without a real relationship/related resource unless makeTable() is overridden.
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertOk();
});

it('renders the AIOStreams relation manager component itself, embedding the browse UI', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(AioStreamsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->assertOk()
        ->assertSee('Search movies');
});
