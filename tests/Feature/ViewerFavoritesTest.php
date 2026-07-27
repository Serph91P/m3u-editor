<?php

use App\Events\ViewerFavoriteEvent;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    $this->childAuth = PlaylistAuth::create([
        'name' => 'Kid',
        'username' => 'kiduser',
        'password' => 'kidpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->childAuth);

    $this->otherViewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'kid',
        'is_admin' => false,
        'playlist_auth_id' => $this->childAuth->id,
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

it('broadcasts a favorite.toggled event scoped to the owner channel for the admin viewer', function () {
    Event::fake([ViewerFavoriteEvent::class]);

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'live',
        'stream_id' => '101',
        'favorited' => 'true',
    ])->assertOk();

    Event::assertDispatched(ViewerFavoriteEvent::class, function (ViewerFavoriteEvent $event) {
        $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());

        return $event->viewerUlid === $this->viewer->ulid
            && $event->contentType === 'live'
            && $event->streamId === 101
            && $event->favorited === true
            && in_array("private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}", $channels, true);
    });
});

it('broadcasts aiostreams metadata on the favorite.toggled event so other devices can render without a re-fetch', function () {
    Event::fake([ViewerFavoriteEvent::class]);

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt0111161',
        'favorited' => 'true',
        'title' => 'The Shawshank Redemption',
        'thumbnail_url' => 'https://example.com/poster.jpg',
        'item_type' => 'movie',
        'aio_integration_id' => '7',
    ])->assertOk();

    Event::assertDispatched(ViewerFavoriteEvent::class, fn (ViewerFavoriteEvent $event) => $event->aioItemId === 'tt0111161'
        && $event->title === 'The Shawshank Redemption'
        && $event->thumbnailUrl === 'https://example.com/poster.jpg'
        && $event->itemType === 'movie'
        && $event->aioIntegrationId === 7);
});

it('broadcasts to the child profile\'s own channel, not the owner channel', function () {
    Event::fake([ViewerFavoriteEvent::class]);

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->otherViewer->ulid,
        'content_type' => 'vod',
        'stream_id' => '202',
        'favorited' => 'true',
    ])->assertOk();

    Event::assertDispatched(ViewerFavoriteEvent::class, function (ViewerFavoriteEvent $event) {
        $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());
        $expected = "private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$this->otherViewer->playlist_auth_id}";

        return $event->viewerUlid === $this->otherViewer->ulid
            && in_array($expected, $channels, true)
            && ! in_array("private-tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}", $channels, true);
    });
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

it('resolves imdb_id/tmdb_id server-side for a vod favorite from the Channel row', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'imdb_id' => 'tt0111161',
        'tmdb_id' => 278,
    ]);

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => "$channel->id",
        'favorited' => 'true',
    ])->assertOk();

    $favorite = ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->first();
    expect($favorite->imdb_id)->toBe('tt0111161');
    expect($favorite->tmdb_id)->toBe('278');
});

it('ignores a client-supplied imdb_id for vod favorites in favor of the server-resolved one', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'imdb_id' => 'tt0111161',
    ]);

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => "$channel->id",
        'favorited' => 'true',
        'imdb_id' => 'tt9999999', // spoofed — must be ignored
    ])->assertOk();

    expect(ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->first()->imdb_id)
        ->toBe('tt0111161');
});

it('resolves imdb_id/tmdb_id server-side for a series favorite from the Series row', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'imdb_id' => 'tt0944947',
        'tmdb_id' => 1399,
    ]);

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'series',
        'stream_id' => "$series->id",
        'favorited' => 'true',
    ])->assertOk();

    $favorite = ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->first();
    expect($favorite->imdb_id)->toBe('tt0944947');
    expect($favorite->tmdb_id)->toBe('1399');
});

it('derives imdb_id for an aiostreams favorite from an IMDb-shaped aio_item_id', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt0111161',
        'favorited' => 'true',
        'title' => 'The Shawshank Redemption',
        'thumbnail_url' => 'https://example.com/poster.jpg',
        'item_type' => 'movie',
        'aio_integration_id' => '7',
    ])->assertOk();

    $favorite = ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->first();
    expect($favorite->imdb_id)->toBe('tt0111161');
    expect($favorite->title)->toBe('The Shawshank Redemption');
    expect($favorite->thumbnail_url)->toBe('https://example.com/poster.jpg');
    expect($favorite->item_type)->toBe('movie');
    expect($favorite->aio_integration_id)->toBe(7);
});

it('trusts a client-supplied imdb_id for aiostreams favorites when the item id is not IMDb-shaped', function () {
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'addon-catalog-id-42',
        'favorited' => 'true',
        'imdb_id' => 'tt0111161',
    ])->assertOk();

    expect(ViewerFavorite::where('playlist_viewer_id', $this->viewer->id)->first()->imdb_id)
        ->toBe('tt0111161');
});

it('cross-references favorites by imdb_id across content types and sources', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'imdb_id' => 'tt0111161',
    ]);

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => "$channel->id",
        'favorited' => 'true',
    ])->assertOk();

    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt0111161',
        'favorited' => 'true',
    ])->assertOk();

    // An unrelated favorite with a different imdb_id must not show up.
    favoritesPost([
        'action' => 'toggle_favorite',
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'aiostreams',
        'aio_item_id' => 'tt9999999',
        'favorited' => 'true',
    ])->assertOk();

    $response = favoritesPost([
        'action' => 'get_favorites',
        'viewer_id' => $this->viewer->ulid,
        'imdb_id' => 'tt0111161',
    ])->assertOk();

    expect($response->json())->toHaveCount(2);
    $contentTypes = collect($response->json())->pluck('content_type')->sort()->values()->all();
    expect($contentTypes)->toBe(['aiostreams', 'vod']);
});
