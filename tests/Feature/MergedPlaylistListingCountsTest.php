<?php

use App\Filament\Resources\MergedPlaylists\Pages\ListMergedPlaylists;
use App\Models\Channel;
use App\Models\Group;
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

it('aggregates groups, live, vod, and series counts across all attached sources', function () {
    $includedSource = Playlist::factory()->create(['user_id' => $this->user->id]);
    $liveExcludedSource = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($includedSource->id);
    $this->merged->playlists()->attach($liveExcludedSource->id, ['include_live' => false]);

    Group::factory()->create(['playlist_id' => $includedSource->id]);
    Group::factory()->create(['playlist_id' => $liveExcludedSource->id]);

    Channel::factory()->create(['playlist_id' => $includedSource->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $liveExcludedSource->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $includedSource->id, 'is_vod' => true]);
    Series::factory()->create(['playlist_id' => $includedSource->id, 'user_id' => $this->user->id]);

    Livewire::test(ListMergedPlaylists::class)
        ->assertTableColumnStateSet('groups_count', 2, record: $this->merged)
        ->assertTableColumnStateSet('live_channels_count', 1, record: $this->merged)
        ->assertTableColumnStateSet('vod_channels_count', 1, record: $this->merged)
        ->assertTableColumnStateSet('series_count', 1, record: $this->merged);
});
