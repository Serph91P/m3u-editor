<?php

use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // aiostreams_integration_id is the actual authorization/assignment field
    // (which integration a playlist may browse) - distinct from playlist_id
    // above, which is only the content-sync target for library imports.
    $this->playlist->update(['aiostreams_integration_id' => $this->integration->id]);
});

it('rewrites each stream candidate url to a proxied url, never returning the raw resolved url', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response([
            'streams' => [
                ['name' => 'Movie.720p', 'url' => 'https://debrid.example.com/secret-token/720p.mkv'],
                ['name' => 'Movie.1080p', 'url' => 'https://debrid.example.com/secret-token/1080p.mkv'],
            ],
        ], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertOk();

    $streams = $response->json('streams');

    expect($streams)->toHaveCount(2);

    foreach ($streams as $stream) {
        expect($stream['url'])
            ->toContain("/aiostreams-media/{$this->integration->id}/live/")
            ->not->toContain('debrid.example.com')
            ->not->toContain('secret-token');
    }
});

it('returns unauthorized for unrecognized credentials', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => []], 200),
    ]);

    $response = $this->get("/{$this->user->name}/wrong-password/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});

// ── #1384 regressions: direct-proxy routes must enforce PlaylistAuth /
// effective-playlist AIOStreams scoping, not just "any enabled integration
// owned by the same user".

it('rejects a valid credential requesting an integration ID assigned to a different playlist owned by the same user', function () {
    $otherPlaylist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $otherIntegration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Other AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/other/manifest.json',
    ]);
    $otherPlaylist->update(['aiostreams_integration_id' => $otherIntegration->id]);

    // Valid owner credentials for $this->playlist, but requesting the
    // OTHER playlist's integration ID by guessing/incrementing it.
    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$otherIntegration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});

it('rejects a PlaylistAuth credential with aiostreams_enabled=false even for its own assigned integration', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'aio_user',
        'password' => 'aio_pass',
        'enabled' => true,
        'aiostreams_enabled' => false,
    ]);
    $auth->assignTo($this->playlist);

    $response = $this->get("/aio_user/aio_pass/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});

it('allows a PlaylistAuth credential with aiostreams_enabled=true for its assigned integration', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => []], 200),
    ]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'aio_user_2',
        'password' => 'aio_pass_2',
        'enabled' => true,
        'aiostreams_enabled' => true,
    ]);
    $auth->assignTo($this->playlist);

    $response = $this->get("/aio_user_2/aio_pass_2/aiostreams/{$this->integration->id}/stream/movie/tt1234567.json");

    $response->assertOk();
});

it('rejects a request for an integration ID that is not assigned to any playlist', function () {
    $unassignedIntegration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Unassigned AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/unassigned/manifest.json',
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$unassignedIntegration->id}/stream/movie/tt1234567.json");

    $response->assertStatus(401);
});
