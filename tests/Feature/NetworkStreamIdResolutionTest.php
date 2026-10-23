<?php

/**
 * Tests for XtreamStreamController::handleLive() resolving a network playlist's
 * HDHR/Xtream stream URL to the correct Network, rather than reinterpreting the
 * route's numeric id (the projected Channel's own primary key) as a Network id.
 *
 * Covers issue #1684: a projected Channel and a Network can have independently
 * allocated, colliding primary keys once other ordinary channels already exist.
 */

use App\Models\Channel;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'testuser']);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'is_network_playlist' => true,
    ]);
});

it('resolves a network playlist stream via the projected channel network_id, even when their primary keys collide', function () {
    // Pre-existing ordinary channel so it claims Channel id=1.
    Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
    ]);

    // Network gets id=1 (independent sequence) while its projected Channel gets id=2.
    $network = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'enabled' => true,
        'broadcast_enabled' => true,
    ]);

    $projectedChannel = Channel::where('network_id', $network->id)->first();

    expect($network->id)->toBe(1)
        ->and($projectedChannel->id)->toBe(2)
        ->and($projectedChannel->id)->not->toBe($network->id);

    // The HDHR lineup/Xtream URL for this network playlist uses the projected
    // Channel's own id (2), not the Network's id (1).
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$projectedChannel->id}.m3u8");

    $response->assertRedirect($network->stream_url);
});

it('returns 404 when the projected channel does not resolve to a network', function () {
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/999999.m3u8");

    $response->assertNotFound();
});
