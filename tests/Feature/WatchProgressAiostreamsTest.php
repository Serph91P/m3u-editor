<?php

use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://example.test/manifest.json',
    ]);

    $this->playlist->update(['aiostreams_integration_id' => $this->integration->id]);
});

it('creates and updates aiostreams watch progress for an admin viewer, keyed by aio_item_id', function () {
    $this->actingAs($this->user);

    $this->postJson('/api/watch-progress', [
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt1234567',
        'aio_integration_id' => $this->integration->id,
        'title' => 'Test Movie',
        'thumbnail_url' => 'https://example.test/poster.jpg',
        'position_seconds' => 120,
        'duration_seconds' => 3600,
    ])->assertOk();

    $progress = ViewerWatchProgress::aiostreams()->where('aio_item_id', 'tt1234567')->first();

    expect($progress)->not->toBeNull();
    expect($progress->title)->toBe('Test Movie');
    expect($progress->position_seconds)->toBe(120);
    $initialWatchCount = $progress->watch_count;

    $viewer = PlaylistViewer::where('viewerable_type', $this->playlist->getMorphClass())
        ->where('viewerable_id', $this->playlist->id)
        ->where('is_admin', true)
        ->first();

    expect($viewer)->not->toBeNull();
    expect($progress->playlist_viewer_id)->toBe($viewer->id);

    // A second update to the same item should not increment watch_count again.
    $this->postJson('/api/watch-progress', [
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt1234567',
        'aio_integration_id' => $this->integration->id,
        'position_seconds' => 180,
        'duration_seconds' => 3600,
    ])->assertOk();

    $progress->refresh();
    expect($progress->position_seconds)->toBe(180);
    expect($progress->watch_count)->toBe($initialWatchCount);
});

it('fetches existing aiostreams watch progress by aio_item_id', function () {
    $this->actingAs($this->user);

    $this->postJson('/api/watch-progress', [
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt7654321',
        'aio_integration_id' => $this->integration->id,
        'position_seconds' => 300,
        'duration_seconds' => 5400,
    ])->assertOk();

    $response = $this->getJson('/api/watch-progress?'.http_build_query([
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt7654321',
        'aio_integration_id' => $this->integration->id,
    ]))->assertOk();

    expect($response->json('position_seconds'))->toBe(300);
});

it('resolves the same viewer for aiostreams progress when the integration is attached to a MergedPlaylist, not a plain Playlist', function () {
    // Regression: the integration here is attached to a MergedPlaylist, and the user also
    // owns an unrelated plain Playlist. Progress must be saved against the MergedPlaylist's
    // viewer (matching what AioStreamsBrowse::resolveViewer() resolves for Continue Watching),
    // not against the unrelated plain Playlist that happened to be the user's first one.
    $this->playlist->update(['aiostreams_integration_id' => null]);
    $merged = MergedPlaylist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $this->integration->id,
    ]);

    $this->actingAs($this->user);

    $this->postJson('/api/watch-progress', [
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt9999999',
        'aio_integration_id' => $this->integration->id,
        'position_seconds' => 90,
        'duration_seconds' => 1800,
    ])->assertOk();

    $progress = ViewerWatchProgress::aiostreams()->where('aio_item_id', 'tt9999999')->first();

    $mergedViewer = PlaylistViewer::where('viewerable_type', $merged->getMorphClass())
        ->where('viewerable_id', $merged->id)
        ->where('is_admin', true)
        ->first();

    expect($mergedViewer)->not->toBeNull();
    expect($progress->playlist_viewer_id)->toBe($mergedViewer->id);

    // A viewer for the unrelated plain Playlist exists too (auto-provisioned whenever
    // any playlist is created — see AppServiceProvider's $autoCreateAdminViewer), but
    // progress must not have been attributed to it.
    $plainPlaylistViewer = PlaylistViewer::where('viewerable_type', $this->playlist->getMorphClass())
        ->where('viewerable_id', $this->playlist->id)
        ->first();
    expect($plainPlaylistViewer)->not->toBeNull();
    expect($progress->playlist_viewer_id)->not->toBe($plainPlaylistViewer->id);
});

it('returns unauthenticated for aiostreams progress with no resolvable viewer', function () {
    $this->postJson('/api/watch-progress', [
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt0000000',
        'aio_integration_id' => $this->integration->id,
        'position_seconds' => 10,
    ])->assertUnauthorized();
});
