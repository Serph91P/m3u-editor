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
    // process. Padding whichever side was behind up to meet the other proved
    // unreliable under a real full-suite/parallel run (something keeps
    // advancing one side between the read and the padding inserts), so force
    // the collision directly instead: pick an id past both sequences' current
    // max, pad channels up to one below it (so the Channel the Network's own
    // creation projects lands exactly there), then set the Network's own id
    // to that same value explicitly - model factories bypass mass-assignment
    // guarding (Model::unguarded()), so 'id' can be set regardless of either
    // model's $guarded/$fillable.
    $collisionId = (int) max(
        DB::table('channels')->max('id') ?? 0,
        DB::table('networks')->max('id') ?? 0,
    ) + 1;

    $nextChannelId = (int) (DB::table('channels')->max('id') ?? 0) + 1;
    while ($nextChannelId < $collisionId) {
        Channel::factory()->for($this->user)->for($this->playlist)->create(['enabled' => true]);
        $nextChannelId++;
    }

    $network = Network::factory()->for($this->user)->create([
        'id' => $collisionId,
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
