<?php

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
});

it('lists playlists, custom playlists, merged playlists, and playlist aliases with a type discriminator', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['name' => 'My Provider']);
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create(['name' => 'Family Lineup']);
    $mergedPlaylist = MergedPlaylist::factory()->for($this->user)->create(['name' => 'All Providers Merged']);
    $alias = PlaylistAlias::create([
        'name' => 'Reseller Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/user/playlists')
        ->assertOk()
        ->assertJsonCount(4);

    $response->assertJsonFragment(['uuid' => $playlist->uuid, 'type' => 'playlist']);
    $response->assertJsonFragment([
        'uuid' => $customPlaylist->uuid,
        'type' => 'custom_playlist',
        'last_sync' => null,
        'status' => null,
        'source_type' => null,
    ]);
    $response->assertJsonFragment([
        'uuid' => $mergedPlaylist->uuid,
        'type' => 'merged_playlist',
        'last_sync' => null,
        'status' => null,
        'source_type' => null,
    ]);
    $response->assertJsonFragment([
        'uuid' => $alias->uuid,
        'type' => 'playlist_alias',
        'last_sync' => null,
        'status' => null,
        'source_type' => null,
    ]);
});

it('does not return another user\'s custom playlists', function () {
    $other = User::factory()->create();
    CustomPlaylist::factory()->for($other)->create();

    $this->withToken($this->token)
        ->getJson('/user/playlists')
        ->assertOk()
        ->assertJsonCount(0);
});
