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
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'testuser']);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'is_network_playlist' => true,
    ]);
});

it('resolves a network playlist stream via the projected channel network_id, even when their primary keys collide', function () {
    // channels and networks are independent auto-increment sequences, and
    // RefreshDatabase doesn't reset either between tests in the same worker
    // process. Pad whichever is behind with disposable filler rows so the
    // Network we create and the Channel its own creation projects land on
    // genuinely equal primary keys - the exact collision #1684 describes -
    // regardless of how many prior tests already advanced either counter.
    $nextChannelId = (int) (DB::table('channels')->max('id') ?? 0) + 1;
    $nextNetworkId = (int) (DB::table('networks')->max('id') ?? 0) + 1;

    // A plain Network with no network_playlist_id projects no Channel (see
    // NetworkChannelSyncService::syncNetwork), so filler networks can close
    // the gap from this side alone when channels is the one running ahead.
    while ($nextNetworkId < $nextChannelId) {
        Network::factory()->for($this->user)->create();
        $nextNetworkId++;
    }

    // The network's own creation projects exactly one Channel row, consuming
    // the next id in each sequence at the same moment - so levelling the two
    // sequences beforehand makes that projected channel's id land exactly on
    // the network's own new id.
    $targetChannelId = $nextNetworkId;

    while ($nextChannelId < $targetChannelId) {
        Channel::factory()->for($this->user)->for($this->playlist)->create(['enabled' => true]);
        $nextChannelId++;
    }

    $network = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'enabled' => true,
        'broadcast_enabled' => true,
    ]);

    $projectedChannel = Channel::where('network_id', $network->id)->latest('id')->first();

    // The collision #1684 describes: these independently allocated primary
    // keys land on the exact same numeric value.
    expect($projectedChannel->id)->toBe($network->id);

    // The HDHR lineup/Xtream URL for this network playlist uses the projected
    // Channel's own id, not the Network's id - the fix must resolve through
    // channel->network_id rather than treating the route id as a Network id.
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$projectedChannel->id}.m3u8");

    $response->assertRedirect($network->stream_url);
});

it('returns 404 when the projected channel does not resolve to a network', function () {
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/999999.m3u8");

    $response->assertNotFound();
});
