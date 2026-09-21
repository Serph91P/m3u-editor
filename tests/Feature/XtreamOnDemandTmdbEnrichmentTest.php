<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function mockOnDemandTmdbSettings(bool $autoEnrichOnFetch): void
{
    test()->mock(GeneralSettings::class, function ($mock) use ($autoEnrichOnFetch) {
        $mock->shouldReceive('getAttribute')->with('tmdb_api_key')->andReturn('fake-api-key');
        $mock->shouldReceive('getAttribute')->with('tmdb_language')->andReturn('en-US');
        $mock->shouldReceive('getAttribute')->with('tmdb_rate_limit')->andReturn(40);
        $mock->shouldReceive('getAttribute')->with('tmdb_confidence_threshold')->andReturn(80);
        $mock->shouldReceive('getAttribute')->with('tmdb_auto_create_groups')->andReturn(false);
        $mock->shouldReceive('getAttribute')->with('tmdb_auto_enrich_on_fetch')->andReturn($autoEnrichOnFetch);
        $mock->tmdb_api_key = 'fake-api-key';
        $mock->tmdb_language = 'en-US';
        $mock->tmdb_rate_limit = 40;
        $mock->tmdb_confidence_threshold = 80;
        $mock->tmdb_auto_create_groups = false;
        $mock->tmdb_auto_enrich_on_fetch = $autoEnrichOnFetch;
    });
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'testuser_'.str()->random(5);
    $this->password = 'testpass';

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);
});

function onDemandXtreamUrl(string $username, string $password, string $action, array $params = []): string
{
    $queryParams = array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params);

    return '/player_api.php?'.http_build_query($queryParams);
}

it('does not call TMDB from get_vod_info when tmdb_auto_enrich_on_fetch is disabled', function () {
    mockOnDemandTmdbSettings(autoEnrichOnFetch: false);
    Http::preventStrayRequests();

    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'last_metadata_fetch' => now(),
        'info' => [],
    ]);

    $response = $this->getJson(onDemandXtreamUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('cast_list');
});

it('enriches a VOD title from TMDB on first view when tmdb_auto_enrich_on_fetch is enabled', function () {
    mockOnDemandTmdbSettings(autoEnrichOnFetch: true);

    Http::fake([
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'overview' => 'A computer hacker learns about the true nature of reality.',
            'poster_path' => '/matrix.jpg',
            'credits' => [
                'cast' => [
                    ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'profile_path' => '/keanu.jpg'],
                ],
            ],
        ], 200),
    ]);

    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'tmdb_id' => 603,
        'last_metadata_fetch' => now(),
        'info' => [],
    ]);

    $response = $this->getJson(onDemandXtreamUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonPath('info.plot', 'A computer hacker learns about the true nature of reality.');
    $response->assertJsonPath('cast_list.0.name', 'Keanu Reeves');

    $channel->refresh();
    expect($channel->info['plot'] ?? null)->toBe('A computer hacker learns about the true nature of reality.');

    // Second view is a one-time-cost no-op: no further TMDB requests are made,
    // enrichment is served entirely from what was persisted on the first view.
    Http::fake(function () {
        throw new RuntimeException('TMDB should not be called again once a title is enriched.');
    });

    $second = $this->getJson(onDemandXtreamUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));
    $second->assertOk();
    $second->assertJsonPath('cast_list.0.name', 'Keanu Reeves');
});

it('does not call TMDB from get_series_info when tmdb_auto_enrich_on_fetch is disabled', function () {
    mockOnDemandTmdbSettings(autoEnrichOnFetch: false);
    Http::preventStrayRequests();

    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Breaking Bad',
        'cover' => '',
        'plot' => '',
        'tmdb_id' => null,
        'metadata' => [],
        'last_modified' => now(),
    ]);

    $response = $this->getJson(onDemandXtreamUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('info.cast_list');
});

it('enriches a series from TMDB on first view when tmdb_auto_enrich_on_fetch is enabled', function () {
    mockOnDemandTmdbSettings(autoEnrichOnFetch: true);

    Http::fake([
        'https://api.themoviedb.org/3/tv/1396*' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
            'overview' => 'A chemistry teacher turns to manufacturing drugs.',
            'poster_path' => '/bb.jpg',
            'credits' => [
                'cast' => [
                    ['id' => 17419, 'name' => 'Bryan Cranston', 'character' => 'Walter White', 'profile_path' => '/bc.jpg'],
                ],
            ],
        ], 200),
    ]);

    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Breaking Bad',
        'cover' => '',
        'plot' => '',
        'tmdb_id' => 1396,
        'metadata' => [],
        'last_modified' => now(),
    ]);

    $response = $this->getJson(onDemandXtreamUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $response->assertJsonPath('info.plot', 'A chemistry teacher turns to manufacturing drugs.');
    $response->assertJsonPath('info.cast_list.0.name', 'Bryan Cranston');

    $series->refresh();
    expect($series->plot)->toBe('A chemistry teacher turns to manufacturing drugs.');
});
