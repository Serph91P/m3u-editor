<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\MoviesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\SeriesRelationManager;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

it('shows the browse catalog header action for an enabled aiostreams integration', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertActionVisible('browseCatalog');
});

it('hides the browse catalog header action for a disabled aiostreams integration', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => false,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertActionHidden('browseCatalog');
});

it('hides the browse catalog header action for non-aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test Jellyfin',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'api_key' => 'secret',
        'enabled' => true,
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertActionHidden('browseCatalog');
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
