<?php

use App\Models\Channel;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
});

it('includes all content types from a source by default', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id);

    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);
    Series::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id]);

    expect($this->merged->channels()->count())->toBe(2)
        ->and($this->merged->live_channels()->count())->toBe(1)
        ->and($this->merged->vod_channels()->count())->toBe(1)
        ->and($this->merged->series()->count())->toBe(1);
});

it('excludes live channels from a source with include_live disabled', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_live' => false]);

    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    $vod = Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);

    expect($this->merged->channels()->pluck('id')->all())->toBe([$vod->id])
        ->and($this->merged->live_channels()->count())->toBe(0)
        ->and($this->merged->vod_channels()->count())->toBe(1);
});

it('excludes vod channels from a source with include_vod disabled', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_vod' => false]);

    $live = Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);

    expect($this->merged->channels()->pluck('id')->all())->toBe([$live->id]);
});

it('excludes series from a source with include_series disabled', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_series' => false]);

    Series::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id]);

    expect($this->merged->series()->count())->toBe(0);
});

it('applies per-source toggles independently across multiple attached sources', function () {
    $liveOnly = Playlist::factory()->create(['user_id' => $this->user->id]);
    $vodOnly = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($liveOnly->id, ['include_vod' => false, 'include_series' => false]);
    $this->merged->playlists()->attach($vodOnly->id, ['include_live' => false]);

    $liveChannel = Channel::factory()->create(['playlist_id' => $liveOnly->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $liveOnly->id, 'is_vod' => true]);
    Channel::factory()->create(['playlist_id' => $vodOnly->id, 'is_vod' => false]);
    $vodChannel = Channel::factory()->create(['playlist_id' => $vodOnly->id, 'is_vod' => true]);

    $ids = $this->merged->channels()->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$liveChannel->id, $vodChannel->id])->sort()->values()->all());
});
