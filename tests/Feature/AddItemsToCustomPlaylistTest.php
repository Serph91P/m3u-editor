<?php

use App\Jobs\AddItemsToCustomPlaylist;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
});

it('syncs items to the custom playlist pivot', function () {
    $channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'My Group Tag'],
        type: 'channel',
    ))->handle();

    foreach ($channels as $channel) {
        expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();
        $channel->refresh();
        expect($channel->tags->pluck('name')->all())->toContain('My Group Tag');
    }
});

it('creates and attaches a new tag in create mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'create', 'new_category' => 'Brand New Tag'],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    expect($channel->tags->pluck('name')->all())->toContain('Brand New Tag');
});

it('re-tagging with a new shared tag replaces the previous tag, not accumulates it', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'First Tag'],
        type: 'channel',
    ))->handle();

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Second Tag'],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    $tagNames = $channel->tags()->where('type', $this->customPlaylist->uuid)->pluck('name')->all();

    expect($tagNames)->toContain('Second Tag')
        ->and($tagNames)->not->toContain('First Tag')
        ->and($tagNames)->toHaveCount(1);
});

it('tags each item with its own group name in original mode, not a batch-wide value', function () {
    $channelA = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => 'Sports',
    ]);
    $channelB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => 'News',
    ]);
    $channelC = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => 'Movies',
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channelA->id, $channelB->id, $channelC->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
    ))->handle();

    $channelA->refresh();
    $channelB->refresh();
    $channelC->refresh();

    expect($channelA->tags->pluck('name')->all())->toContain('Sports')
        ->and($channelB->tags->pluck('name')->all())->toContain('News')
        ->and($channelC->tags->pluck('name')->all())->toContain('Movies');
});

it('does not skip a group literally named "0" in original mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => '0',
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
    ))->handle();

    $channel->refresh();

    expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue()
        ->and($channel->tags->pluck('name')->all())->toContain('0');
});

it('does not create a tag for items with no group set in original mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group' => null,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$channel->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
    ))->handle();

    $channel->refresh();

    expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue()
        ->and($channel->tags)->toBeEmpty();
});

it('handles a selection spanning multiple chunk boundaries', function () {
    $channels = Channel::factory()->count(1200)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: $channels->pluck('id')->all(),
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'category' => 'Bulk Tag'],
        type: 'channel',
    ))->handle();

    expect($this->customPlaylist->channels()->count())->toBe(1200);
});

it('syncs series to the custom playlist and tags with category name in original mode', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Drama',
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
    ]);

    (new AddItemsToCustomPlaylist(
        userId: $this->user->id,
        itemIds: [$series->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'series',
    ))->handle();

    $series->refresh();

    expect($this->customPlaylist->series()->where('series.id', $series->id)->exists())->toBeTrue()
        ->and($series->tags->pluck('name')->all())->toContain('Drama');
});
