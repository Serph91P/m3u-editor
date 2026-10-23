<?php

use App\Jobs\RequestArrEpisode;
use App\Livewire\ArrSearch;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Notification::fake();
    Http::preventStrayRequests();
    config(['queue.default' => 'sync']);
    Illuminate\Support\Facades\RateLimiter::swap(new RateLimiter(
        Cache::store('array'),
    ));
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    foreach (['radarr', 'sonarr'] as $type) {
        foreach (['A', 'B'] as $name) {
            $this->instances[$type][$name] = ArrIntegration::factory()->create([
                'user_id' => $this->user->id,
                'type' => $type,
                'name' => "$type $name",
                'url' => 'http://'.$type.'-'.strtolower($name).'.test',
                'quality_profile_id' => $name === 'A' ? 11 : 22,
                'root_folder_path' => "/$type/$name",
            ]);
        }
    }
});

it('requests a discovered movie on the explicitly selected Radarr with its own defaults', function () {
    $a = $this->instances['radarr']['A'];
    $b = $this->instances['radarr']['B'];
    Http::fake([
        "$a->url/api/v3/movie/lookup*" => Http::response([['id' => 101, 'tmdbId' => 123, 'title' => 'Synthetic movie', 'hasFile' => true]]),
        "$b->url/api/v3/movie/lookup*" => Http::response([['tmdbId' => 123, 'title' => 'Synthetic movie']]),
        "$b->url/api/v3/movie" => Http::response(['id' => 202], 201),
    ]);

    $component = Livewire::test(ArrSearch::class)
        ->dispatch('request-from-discover', tmdbId: 123, mediaType: 'movie')
        ->assertSet('detailIntegrationId', $a->id)
        ->assertSet('detailResult.libraryId', 101);
    expect($component->instance()->showDetailAction()->getModalContent()->render())->toContain('Target instance', 'radarr B');
    $component->set('detailIntegrationId', $b->id)
        ->assertSet('detailResult.libraryId', null)
        ->assertSet('detailResult.existsInLibrary', false)
        ->assertSet('detailResult.hasFile', false)
        ->call('request', 0);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === "$b->url/api/v3/movie"
        && $request['tmdbId'] === 123
        && $request['qualityProfileId'] === 22
        && $request['rootFolderPath'] === '/radarr/B');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && str_starts_with($request->url(), $a->url));
});

it('discards the previous Sonarr episode and rejects its release after switching destinations', function () {
    $a = $this->instances['sonarr']['A'];
    $b = $this->instances['sonarr']['B'];
    foreach (['A' => $a, 'B' => $b] as $name => $integration) {
        $id = $name === 'A' ? 101 : 202;
        $fakes["$integration->url/api/v3/series/lookup*"] = Http::response([[
            'id' => $id, 'tvdbId' => 321, 'title' => 'Synthetic series', 'seasons' => [['seasonNumber' => 1]],
        ]]);
        $fakes["$integration->url/api/v3/episode*"] = Http::response([[
            'id' => $id + 1, 'seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => $name === 'A',
        ]]);
        $fakes["$integration->url/api/v3/release*"] = Http::response([[
            'guid' => "release-$name", 'indexerId' => $id, 'title' => "Release $name",
        ]]);
    }
    $fakes['api.tvmaze.com/*'] = Http::response([], 404);
    $fakes['*/api/v3/movie/lookup*'] = Http::response([]);
    Http::fake($fakes);

    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'Synthetic')->call('search')->call('openDetail', 0)
        ->call('loadEpisodeReleases', 1, 1)
        ->assertSet('detailEpisodeId', 102)
        ->set('detailIntegrationId', $b->id)
        ->assertSet('detailResult.libraryId', 202)
        ->assertSet('detailReleases', [])
        ->assertSet('detailEpisodeId', null)
        ->assertSet('detailEpisodeSeason', null)
        ->assertSet('detailEpisodeNumber', null)
        ->assertSet('detailReleasesLabel', null)
        ->assertSet('detailSonarrEpisodeStatus.1.1', false)
        ->call('loadEpisodeReleases', 1, 1)
        ->assertSet('detailEpisodeId', 203)
        ->call('downloadDetailRelease', 'release-A', 101);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    $component->call('downloadDetailRelease', 'release-B', 202);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === "$b->url/api/v3/release"
        && $request['seriesId'] === 202 && $request['episodeId'] === 203
        && $request['guid'] === 'release-B' && $request['indexerId'] === 202);
});

it('rechecks eligibility before a result request after the integration is disabled', function () {
    $a = $this->instances['radarr']['A'];
    Http::fake([
        "$a->url/api/v3/movie/lookup*" => Http::response([['tmdbId' => 123, 'title' => 'Synthetic movie']]),
        "$a->url/api/v3/movie" => Http::response(['id' => 101], 201),
    ]);
    $component = Livewire::test(ArrSearch::class)
        ->dispatch('request-from-discover', tmdbId: 123, mediaType: 'movie');
    $a->update(['enabled' => false]);
    $component->call('request', 0);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('does not accept client changes to server-owned destination context', function (string $property, mixed $value) {
    Http::fake();
    expect(fn () => Livewire::test(ArrSearch::class)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    ['guestIntegrationIds', [999]],
    ['guestMode', true],
    ['playlistAuthId', 999],
    ['autoApproveRequests', true],
    ['results', [['integrationId' => 999, 'integrationType' => 'radarr']]],
    ['detailResult', ['libraryId' => 999, 'integrationType' => 'sonarr']],
    ['detailIndex', 999],
    ['detailReleases', [['guid' => 'stale', 'indexerId' => 1]]],
    ['detailEpisodeId', 999],
    ['detailEpisodeSeason', 99],
    ['detailEpisodeNumber', 99],
]);

it('rejects an ineligible destination before contacting any server', function (string $case) {
    $a = $this->instances['radarr']['A'];
    $target = $this->instances['radarr']['B'];
    if ($case === 'foreign') {
        $target->update(['user_id' => User::factory()->create()->id]);
    } elseif ($case === 'disabled') {
        $target->update(['enabled' => false]);
    } elseif ($case === 'wrong type') {
        $target = $this->instances['sonarr']['B'];
    }
    Http::fake([
        "$a->url/api/v3/movie/lookup*" => Http::response([['tmdbId' => 123, 'title' => 'Synthetic movie']]),
        "$a->url/api/v3/queue*" => Http::response(['records' => []]),
    ]);
    $component = Livewire::test(ArrSearch::class, $case === 'guest not allowed' ? [
        'guestMode' => true, 'guestIntegrationIds' => [$a->id],
    ] : [])->dispatch('request-from-discover', tmdbId: 123, mediaType: 'movie');
    Http::fake();
    $component->set('detailIntegrationId', $case === 'missing' ? 999999 : $target->id)->assertForbidden();
    Http::assertNothingSent();
})->with(['foreign', 'disabled', 'wrong type', 'guest not allowed', 'missing']);

it('requests selected seasons on Sonarr B rather than using the library and defaults of A', function () {
    $a = $this->instances['sonarr']['A'];
    $b = $this->instances['sonarr']['B'];
    config(['services.tmdb.api_key' => 'synthetic']);
    Http::fake([
        'api.themoviedb.org/3/tv/123/external_ids*' => Http::response(['tvdb_id' => 321]),
        "$a->url/api/v3/series/lookup*" => Http::response([[
            'id' => 101, 'tvdbId' => 321, 'title' => 'Synthetic series', 'seasons' => [['seasonNumber' => 1]],
        ]]),
        "$b->url/api/v3/series/lookup*" => Http::response([[
            'tvdbId' => 321, 'title' => 'Synthetic series', 'seasons' => [['seasonNumber' => 1], ['seasonNumber' => 2]],
        ]]),
        "$b->url/api/v3/series" => Http::response(['id' => 202], 201),
        'api.tvmaze.com/*' => Http::response([], 404),
        'api.themoviedb.org/3/tv/123/credits*' => Http::response(['cast' => []]),
    ]);
    Livewire::test(ArrSearch::class)
        ->dispatch('request-from-discover', tmdbId: 123, mediaType: 'tv')
        ->assertSet('detailIntegrationId', $a->id)
        ->set('detailIntegrationId', $b->id)
        ->assertSet('detailResult.libraryId', null)
        ->set('selectedSeasons.1', false)
        ->call('requestDetail');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === "$b->url/api/v3/series"
        && $request['qualityProfileId'] === 22 && $request['rootFolderPath'] === '/sonarr/B'
        && $request['seasons'] === [['seasonNumber' => 1, 'monitored' => false], ['seasonNumber' => 2, 'monitored' => true]]);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && str_starts_with($request->url(), $a->url));
});

it('clears instance episode status when opening another search result', function () {
    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([]),
        '*/api/v3/series/lookup*' => Http::response([[
            'id' => 101, 'tvdbId' => 321, 'title' => 'Synthetic series', 'seasons' => [['seasonNumber' => 1]],
        ]]),
        '*/api/v3/episode*' => Http::response([[
            'seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true,
            'episodeFile' => ['quality' => ['quality' => ['name' => 'HD']], 'size' => 1024],
        ]]),
        'api.tvmaze.com/*' => Http::response([], 404),
    ]);
    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'Synthetic')->call('search')
        ->call('openDetail', 0)->call('loadDetailEpisodes')
        ->assertSet('detailSonarrEpisodeStatus.1.1', true)
        ->call('openDetail', 1)
        ->assertSet('detailSonarrEpisodeStatus', [])
        ->assertSet('detailSonarrEpisodeFileInfo', []);
});

it('rechecks the selected destination before every interactive action', function (string $method, array $arguments) {
    $a = $this->instances['sonarr']['A'];
    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([]),
        '*/api/v3/series/lookup*' => Http::response([[
            'id' => 101, 'tvdbId' => 321, 'title' => 'Synthetic series', 'seasons' => [['seasonNumber' => 1]],
        ]]),
    ]);
    $component = Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'Synthetic')->call('search')->call('openDetail', 0);
    $a->update(['enabled' => false]);
    Http::fake();
    $component->call($method, ...$arguments);
    Http::assertNothingSent();
})->with([
    ['request', [0]],
    ['requestDetail', []],
    ['requestEpisode', [1, 1]],
    ['triggerAutomaticSearch', []],
    ['loadDetailReleases', []],
    ['loadEpisodeReleases', [1, 1]],
    ['downloadDetailRelease', ['release-A', 101]],
    ['loadDetailEpisodes', []],
]);

it('uses Radarr B for adding and downloading an interactive movie release', function () {
    $a = $this->instances['radarr']['A'];
    $b = $this->instances['radarr']['B'];
    Http::fake([
        "$a->url/api/v3/movie/lookup*" => Http::response([['id' => 101, 'tmdbId' => 123, 'title' => 'Synthetic movie', 'hasFile' => true]]),
        "$b->url/api/v3/movie/lookup*" => Http::response([['tmdbId' => 123, 'title' => 'Synthetic movie']]),
        "$b->url/api/v3/movie" => Http::response(['id' => 202], 201),
        "$b->url/api/v3/release*" => Http::response([['guid' => 'release-B', 'indexerId' => 22]]),
    ]);
    Livewire::test(ArrSearch::class)
        ->dispatch('request-from-discover', tmdbId: 123, mediaType: 'movie')
        ->set('detailIntegrationId', $b->id)
        ->call('addForInteractiveSearch')
        ->assertSet('detailResult.libraryId', 202)
        ->call('downloadDetailRelease', 'release-B', 22);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === "$b->url/api/v3/movie"
        && $request['qualityProfileId'] === 22 && $request['rootFolderPath'] === '/radarr/B'
        && $request['addOptions']['searchForMovie'] === false);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === "$b->url/api/v3/release"
        && $request['movieId'] === 202 && $request['guid'] === 'release-B');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && str_starts_with($request->url(), $a->url));
});

it('keeps the single-instance detail flow without an unnecessary picker', function () {
    $this->instances['radarr']['B']->update(['enabled' => false]);
    $a = $this->instances['radarr']['A'];
    Http::fake([
        "$a->url/api/v3/movie/lookup*" => Http::response([['tmdbId' => 123, 'title' => 'Synthetic movie']]),
        "$a->url/api/v3/movie" => Http::response(['id' => 101], 201),
    ]);
    $component = Livewire::test(ArrSearch::class)
        ->dispatch('request-from-discover', tmdbId: 123, mediaType: 'movie');
    expect($component->instance()->showDetailAction()->getModalContent()->render())->not->toContain('arr-detail-integration');
    $component->call('request', 0);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === "$a->url/api/v3/movie"
        && $request['qualityProfileId'] === 11 && $request['rootFolderPath'] === '/radarr/A');
});

it('queues a guest request for the selected allowed destination without forwarding it', function () {
    $a = $this->instances['radarr']['A'];
    $b = $this->instances['radarr']['B'];
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $this->user->id, 'request_enabled' => true, 'auto_approve_requests' => false,
    ]);
    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([['tmdbId' => 123, 'title' => 'Synthetic movie']]),
        '*/api/v3/queue*' => Http::response(['records' => []]),
    ]);
    Livewire::test(ArrSearch::class, [
        'guestMode' => true, 'guestIntegrationIds' => [$a->id, $b->id], 'playlistAuthId' => $auth->id,
    ])->dispatch('request-from-discover', tmdbId: 123, mediaType: 'movie')
        ->set('detailIntegrationId', $b->id)->call('request', 0);
    $request = MediaRequest::sole();
    expect($request->arr_integration_id)->toBe($b->id)
        ->and($request->status)->toBe('pending')
        ->and($request->payload['qualityProfileId'])->toBe(22)
        ->and($request->payload['rootFolderPath'])->toBe('/radarr/B');
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('requests an episode on Sonarr B using B defaults and the B series ID', function () {
    $b = $this->instances['sonarr']['B'];
    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([]),
        '*/api/v3/series/lookup*' => Http::response([[
            'tvdbId' => 321, 'title' => 'Synthetic series', 'titleSlug' => 'synthetic', 'seasons' => [['seasonNumber' => 1]],
        ]]),
        "$b->url/api/v3/series" => Http::response(['id' => 202], 201),
        'api.tvmaze.com/*' => Http::response([], 404),
    ]);
    Livewire::test(ArrSearch::class)
        ->set('searchTerm', 'Synthetic')->call('search')->call('openDetail', 0)
        ->set('detailIntegrationId', $b->id)->call('requestEpisode', 1, 1);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === "$b->url/api/v3/series"
        && $request['qualityProfileId'] === 22 && $request['rootFolderPath'] === '/sonarr/B'
        && $request['addOptions']['searchForMissingEpisodes'] === false);
    Bus::assertDispatched(RequestArrEpisode::class, fn ($job) => $job->integrationId === $b->id && $job->sonarrSeriesId === 202);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && ! str_starts_with($request->url(), $b->url));
});
