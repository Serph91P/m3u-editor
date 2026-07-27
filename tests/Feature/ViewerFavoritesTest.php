<?php

use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Test',
        'username' => 'testuser',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->playlistAuth);

    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => true,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);

    $this->otherViewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'kid',
        'is_admin' => false,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);
});

function favoritesPost(array $body): TestResponse
{
    return test()->postJson(route('xtream.api.player'), array_merge([
        'username' => 'testuser',
        'password' => 'testpass',
    ], $body));
}

it('adds a favorite and returns it via get_favorites', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'live',
        'stream_id' => '101',
        'favorited' => 'true',
    ])->assertOk()->assertJson(['favorited' => true]);

    $response = favoritesPost([
        'action' => 'get_favorites',
        'viewer_id' => $this->viewer->ulid,
    ])->assertOk();

    expect($response->json())->toHaveCount(1);
    expect($response->json()[0])
        ->content_type->toBe('live')
        ->stream_id->toBe(101);
});

it('removes a favorite when favorited is false', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => '202',
        'favorited' => 'true',
    ])->assertOk();

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => '202',
        'favorited' => 'false',
    ])->assertOk()->assertJson(['favorited' => false]);

    expect(ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->count())->toBe(0);
});

it('is idempotent when favoriting an already-favorited item', function () {
    $body = [
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'series',
        'stream_id' => '303',
        'favorited' => 'true',
    ];

    favoritesPost($body)->assertOk();
    favoritesPost($body)->assertOk();

    expect(ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->count())->toBe(1);
});

it('favorites AIOStreams items by aio_item_id instead of stream_id', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt1234567',
        'favorited' => 'true',
    ])->assertOk();

    $response = favoritesPost([
        'action' => 'get_favorites',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'aiostreams',
    ])->assertOk();

    expect($response->json())->toHaveCount(1);
    expect($response->json()[0])
        ->content_type->toBe('aiostreams')
        ->aio_item_id->toBe('tt1234567')
        ->stream_id->toBeNull();
});

it('isolates favorites between viewers on the same playlist', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'live',
        'stream_id' => '101',
        'favorited' => 'true',
    ])->assertOk();

    $response = favoritesPost([
        'action' => 'get_favorites',
        'viewer_id' => $this->otherViewer->ulid,
    ])->assertOk();

    expect($response->json())->toBeEmpty();
});

it('rejects an invalid content_type', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'not-a-real-type',
        'stream_id' => '101',
    ])->assertStatus(400);
});

it('filters get_favorites by content_type', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'live',
        'stream_id' => '101',
        'favorited' => 'true',
    ])->assertOk();

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => '202',
        'favorited' => 'true',
    ])->assertOk();

    $response = favoritesPost([
        'action' => 'get_favorites',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
    ])->assertOk();

    expect($response->json())->toHaveCount(1);
    expect($response->json()[0])->content_type->toBe('vod');
});

it('syncs local-only favorites by merging them into the server set without deleting existing ones', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'live',
        'stream_id' => '101',
        'favorited' => 'true',
    ])->assertOk();

    $response = favoritesPost([
        'action' => 'sync_favorites',
        'viewer_id' => $this->viewer->ulid,
        'favorites' => [
            ['content_type' => 'live', 'stream_id' => '101'], // already on server
            ['content_type' => 'vod', 'stream_id' => '202'],  // local-only, new to server
        ],
    ])->assertOk();

    expect($response->json())->toHaveCount(2);
    expect(ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->count())->toBe(2);
});

it('ignores malformed entries in sync_favorites instead of failing the whole request', function () {
    $response = favoritesPost([
        'action' => 'sync_favorites',
        'viewer_id' => $this->viewer->ulid,
        'favorites' => [
            ['content_type' => 'live'], // missing stream_id
            ['content_type' => 'bogus', 'stream_id' => '1'], // invalid content_type
            ['content_type' => 'live', 'stream_id' => '101'], // valid
        ],
    ])->assertOk();

    expect($response->json())->toHaveCount(1);
});
