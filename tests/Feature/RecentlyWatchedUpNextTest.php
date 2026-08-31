<?php

use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Test',
        'username' => 'testuser',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->playlistAuth);

    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => false,
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);
});

function makeEpisode(int $seriesId, int $season, int $episodeNum, array $overrides = []): Episode
{
    return Episode::factory()->create(array_merge([
        'series_id' => $seriesId,
        'season' => $season,
        'episode_num' => $episodeNum,
        'enabled' => true,
        'is_aio_failover_clone' => false,
        'title' => "S{$season}E{$episodeNum}",
        'info' => ['duration' => '1800'],
    ], $overrides));
}

function recordProgress(PlaylistViewer $viewer, Episode $episode, bool $completed, string $watchedAt): void
{
    ViewerWatchProgress::create([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'episode',
        'stream_id' => $episode->id,
        'series_id' => $episode->series_id,
        'season_number' => $episode->season,
        'episode_number' => $episode->episode_num,
        'position_seconds' => $completed ? 1700 : 120,
        'duration_seconds' => 1800,
        'completed' => $completed,
        'last_watched_at' => $watchedAt,
    ]);
}

function getRecentlyWatched(PlaylistViewer $viewer, array $extra = []): TestResponse
{
    $query = http_build_query(array_merge([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'get_recently_watched',
        'viewer_id' => $viewer->ulid,
    ], $extra));

    return test()->getJson(route('xtream.api.player').'?'.$query);
}

it('surfaces the next episode as up_next when the latest watched episode is completed', function () {
    $series = Series::factory()->for($this->user)->create();
    $e1 = makeEpisode($series->id, 1, 1);
    $e2 = makeEpisode($series->id, 1, 2);

    recordProgress($this->viewer, $e1, completed: true, watchedAt: now()->toDateTimeString());

    $response = getRecentlyWatched($this->viewer, ['include_up_next' => '1'])->assertOk();

    $upNext = collect($response->json())->firstWhere('up_next', true);
    expect($upNext)->not->toBeNull()
        ->and($upNext['stream_id'])->toBe($e2->id)
        ->and($upNext['position_seconds'])->toBe(0)
        ->and($upNext['completed'])->toBeFalse()
        ->and($upNext['content_type'])->toBe('episode');
});

it('omits up_next entries when the flag is not passed', function () {
    $series = Series::factory()->for($this->user)->create();
    $e1 = makeEpisode($series->id, 1, 1);
    makeEpisode($series->id, 1, 2);

    recordProgress($this->viewer, $e1, completed: true, watchedAt: now()->toDateTimeString());

    $response = getRecentlyWatched($this->viewer)->assertOk();

    expect(collect($response->json())->contains('up_next', true))->toBeFalse();
});

it('does not surface up_next past the last available episode', function () {
    $series = Series::factory()->for($this->user)->create();
    $e1 = makeEpisode($series->id, 1, 1);

    recordProgress($this->viewer, $e1, completed: true, watchedAt: now()->toDateTimeString());

    $response = getRecentlyWatched($this->viewer, ['include_up_next' => '1'])->assertOk();

    expect(collect($response->json())->contains('up_next', true))->toBeFalse();
});

it('crosses season boundaries for up_next', function () {
    $series = Series::factory()->for($this->user)->create();
    $s1e1 = makeEpisode($series->id, 1, 1);
    $s2e1 = makeEpisode($series->id, 2, 1);

    recordProgress($this->viewer, $s1e1, completed: true, watchedAt: now()->toDateTimeString());

    $response = getRecentlyWatched($this->viewer, ['include_up_next' => '1'])->assertOk();

    $upNext = collect($response->json())->firstWhere('up_next', true);
    expect($upNext['stream_id'])->toBe($s2e1->id)
        ->and($upNext['season_number'])->toBe(2)
        ->and($upNext['episode_number'])->toBe(1);
});

it('does not add an up_next entry when the next episode already has progress', function () {
    $series = Series::factory()->for($this->user)->create();
    $e1 = makeEpisode($series->id, 1, 1);
    $e2 = makeEpisode($series->id, 1, 2);

    recordProgress($this->viewer, $e1, completed: true, watchedAt: now()->subMinute()->toDateTimeString());
    recordProgress($this->viewer, $e2, completed: false, watchedAt: now()->toDateTimeString());

    $response = getRecentlyWatched($this->viewer, ['include_up_next' => '1'])->assertOk();

    $forE2 = collect($response->json())->where('stream_id', $e2->id);
    expect($forE2)->toHaveCount(1)
        ->and($forE2->first()['up_next'] ?? false)->toBeFalse();
});

it('places the up_next entry in the finished episode recency slot', function () {
    $series = Series::factory()->for($this->user)->create();
    $e1 = makeEpisode($series->id, 1, 1);
    $e2 = makeEpisode($series->id, 1, 2);
    $olderSeries = Series::factory()->for($this->user)->create();
    $other = makeEpisode($olderSeries->id, 1, 1);

    recordProgress($this->viewer, $other, completed: false, watchedAt: now()->subHours(2)->toDateTimeString());
    recordProgress($this->viewer, $e1, completed: true, watchedAt: now()->subMinutes(5)->toDateTimeString());

    $response = getRecentlyWatched($this->viewer, ['include_up_next' => '1'])->assertOk();

    $streamIds = collect($response->json())->pluck('stream_id')->all();
    expect(array_search($e2->id, $streamIds, true))
        ->toBeLessThan(array_search($other->id, $streamIds, true));
});
