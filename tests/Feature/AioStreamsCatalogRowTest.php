<?php

use App\Livewire\AioStreamsCatalogRow;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);
});

function mountRow(int $integrationId): Testable
{
    return Livewire::test(AioStreamsCatalogRow::class, [
        'integrationId' => $integrationId,
        'catalogId' => 'top',
        'catalogType' => 'movie',
        'catalogName' => 'Top Movies',
    ]);
}

it('does not fetch the catalog on initial mount', function () {
    mountRow($this->integration->id)
        ->assertOk()
        ->assertSet('loaded', false)
        ->assertSet('items', []);

    Http::assertNothingSent();
});

it('fetches the catalog only once loadIfVisible is called', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response([
            'metas' => [
                ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
            ],
        ]),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->assertSet('loaded', true)
        ->assertSet('loadFailed', false)
        ->assertSet('items', [
            ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
        ]);

    Http::assertSentCount(1);
});

it('does not refetch on a repeated loadIfVisible call', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
    ]);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->call('loadIfVisible');

    Http::assertSentCount(1);
});

it('serves a second component instance from cache without re-hitting the network', function () {
    Http::fake([
        '*/catalog/movie/top.json' => Http::response(['metas' => [['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One']]]),
    ]);

    mountRow($this->integration->id)->call('loadIfVisible');
    Http::assertSentCount(1);

    mountRow($this->integration->id)
        ->call('loadIfVisible')
        ->assertSet('items', [
            ['id' => 'tt1', 'type' => 'movie', 'name' => 'Movie One'],
        ]);
    Http::assertSentCount(1);
});
