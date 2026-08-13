<?php

use App\Filament\Resources\MergedPlaylists\Pages\EditMergedPlaylist;
use App\Filament\Resources\MergedPlaylists\RelationManagers\PlaylistsRelationManager;
use App\Models\Channel;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

it('attaches a source with all content types included by default', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->callTableAction('attach', data: [
            'recordId' => $playlist->id,
            'include_live' => true,
            'include_vod' => true,
            'include_series' => true,
        ]);

    $pivot = $this->merged->playlists()->find($playlist->id)->pivot;
    expect($pivot->include_live)->toBeTrue()
        ->and($pivot->include_vod)->toBeTrue()
        ->and($pivot->include_series)->toBeTrue();
});

it('attaches a source with only selected content types included', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->callTableAction('attach', data: [
            'recordId' => $playlist->id,
            'include_live' => true,
            'include_vod' => false,
            'include_series' => false,
        ]);

    $pivot = $this->merged->playlists()->find($playlist->id)->pivot;
    expect($pivot->include_live)->toBeTrue()
        ->and($pivot->include_vod)->toBeFalse()
        ->and($pivot->include_series)->toBeFalse();
});

it('edits the content types included for an already-attached source', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, [
        'include_live' => true,
        'include_vod' => true,
        'include_series' => true,
    ]);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->callTableAction('contentTypes', $playlist, data: [
            'include_live' => true,
            'include_vod' => false,
            'include_series' => true,
        ]);

    $pivot = $this->merged->playlists()->find($playlist->id)->pivot;
    expect($pivot->include_live)->toBeTrue()
        ->and($pivot->include_vod)->toBeFalse()
        ->and($pivot->include_series)->toBeTrue();
});

it('prefills the content types form with the source current pivot values', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, [
        'include_live' => false,
        'include_vod' => true,
        'include_series' => false,
    ]);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->mountTableAction('contentTypes', $playlist)
        ->assertTableActionDataSet([
            'include_live' => false,
            'include_vod' => true,
            'include_series' => false,
        ]);
});

it('shows live, vod, and series counts for each attached source', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id);

    Channel::factory()->count(2)->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);
    Series::factory()->count(3)->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id]);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->assertTableColumnStateSet('live_channels_count', 2, record: $playlist)
        ->assertTableColumnStateSet('vod_channels_count', 1, record: $playlist)
        ->assertTableColumnStateSet('series_count', 3, record: $playlist);
});

it('zeroes out the count for a content type excluded via the per-source toggle', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, [
        'include_live' => false,
        'include_vod' => true,
        'include_series' => false,
    ]);

    Channel::factory()->count(2)->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);
    Series::factory()->count(3)->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id]);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->assertTableColumnStateSet('live_channels_count', 0, record: $playlist)
        ->assertTableColumnStateSet('vod_channels_count', 1, record: $playlist)
        ->assertTableColumnStateSet('series_count', 0, record: $playlist);
});
