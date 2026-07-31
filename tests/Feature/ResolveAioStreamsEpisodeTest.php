<?php

use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\AioStreamsQualityParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
    $this->series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
    ]);
    $this->episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'aio_resolution_status' => 'pending',
        'url' => null,
    ]);
});

it('resolves the top candidates, infers the container extension, and creates a failover chain', function () {
    Http::fake([
        'aiostreams.test/abc/stream/series/tt1:1:1.json*' => Http::response([
            'streams' => [
                ['name' => 'Episode.720p', 'url' => 'https://example.com/720p.mkv'],
                ['name' => 'Episode.2160p.HDR10', 'url' => 'https://example.com/2160p.mkv'],
                ['name' => 'Episode.1080p', 'url' => 'https://example.com/1080p.mkv'],
            ],
        ], 200),
    ]);

    (new ResolveAioStreamsEpisode($this->episode->id))->handle(new AioStreamsQualityParser);

    $this->episode->refresh();

    expect($this->episode->aio_resolution_status)->toBe('resolved')
        ->and($this->episode->url)->toBe('https://example.com/2160p.mkv')
        ->and($this->episode->container_extension)->toBe('mkv')
        ->and(EpisodeFailover::where('episode_id', $this->episode->id)->count())->toBe(2);
});

it('creates failover candidates as hidden clones that do not appear in normal Episode queries', function () {
    Http::fake([
        'aiostreams.test/abc/stream/series/tt1:1:1.json*' => Http::response([
            'streams' => [
                ['name' => 'Episode.720p', 'url' => 'https://example.com/720p.mkv'],
                ['name' => 'Episode.1080p', 'url' => 'https://example.com/1080p.mkv'],
            ],
        ], 200),
    ]);

    (new ResolveAioStreamsEpisode($this->episode->id))->handle(new AioStreamsQualityParser);

    $failoverEpisodeIds = EpisodeFailover::where('episode_id', $this->episode->id)->pluck('episode_failover_id');
    expect($failoverEpisodeIds)->toHaveCount(1);

    // Hidden from plain Eloquent queries (series episode listing, Xtream API, etc.)...
    expect(Episode::whereIn('id', $failoverEpisodeIds)->count())->toBe(0)
        ->and($this->series->fresh()->episodes()->count())->toBe(1);

    // ...but still reachable via the failover relationship the playback path uses.
    expect($this->episode->failoverEpisodes()->count())->toBe(1);
});

it('deletes orphaned failover clone episodes when the primary episode is deleted', function () {
    Http::fake([
        'aiostreams.test/abc/stream/series/tt1:1:1.json*' => Http::response([
            'streams' => [
                ['name' => 'Episode.720p', 'url' => 'https://example.com/720p.mkv'],
                ['name' => 'Episode.1080p', 'url' => 'https://example.com/1080p.mkv'],
            ],
        ], 200),
    ]);

    (new ResolveAioStreamsEpisode($this->episode->id))->handle(new AioStreamsQualityParser);

    $failoverEpisodeIds = EpisodeFailover::where('episode_id', $this->episode->id)->pluck('episode_failover_id');
    expect($failoverEpisodeIds)->toHaveCount(1);

    $this->episode->delete();

    expect(Episode::withoutGlobalScopes()->whereIn('id', $failoverEpisodeIds)->count())->toBe(0);
});

it('re-enables an episode that was disabled for being unaired once it resolves', function () {
    $this->episode->update([
        'enabled' => false,
        'aio_resolution_status' => 'scheduled',
    ]);

    Http::fake([
        'aiostreams.test/abc/stream/series/tt1:1:1.json*' => Http::response([
            'streams' => [
                ['name' => 'Episode.1080p', 'url' => 'https://example.com/1080p.mkv'],
            ],
        ], 200),
    ]);

    (new ResolveAioStreamsEpisode($this->episode->id))->handle(new AioStreamsQualityParser);

    $this->episode->refresh();

    expect($this->episode->enabled)->toBeTrue()
        ->and($this->episode->aio_resolution_status)->toBe('partial');
});

it('retries with a delay when AIOStreams returns no results, and eventually marks failed', function () {
    Bus::fake();

    Http::fake([
        'aiostreams.test/*' => Http::response(['streams' => []], 200),
    ]);

    (new ResolveAioStreamsEpisode($this->episode->id, attempt: 1))->handle(new AioStreamsQualityParser);

    Bus::assertDispatched(ResolveAioStreamsEpisode::class, function (ResolveAioStreamsEpisode $job) {
        return $job->episodeId === $this->episode->id && $job->attempt === 2;
    });

    $this->episode->refresh();
    expect($this->episode->aio_resolution_status)->toBe('pending');

    (new ResolveAioStreamsEpisode($this->episode->id, attempt: 3))->handle(new AioStreamsQualityParser);

    $this->episode->refresh();
    expect($this->episode->aio_resolution_status)->toBe('failed');
});
