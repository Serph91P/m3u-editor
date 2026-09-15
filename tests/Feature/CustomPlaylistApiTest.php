<?php

use App\Jobs\AddItemsToCustomPlaylist;
use App\Jobs\DetachItemsFromCustomPlaylist;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = CustomPlaylist::factory()->for($this->user)->create();
    $this->token = $this->user->createToken('test')->plainTextToken;
});

it('queues an attach job for multiple channels', function () {
    Bus::fake();

    $channels = Channel::factory()->for($this->user)->count(2)->create();

    $this->withToken($this->token)
        ->postJson("/custom-playlist/{$this->playlist->uuid}/channels", [
            'ids' => $channels->pluck('id')->all(),
            'group' => 'Sports',
        ])
        ->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.queued_channel_ids', $channels->pluck('id')->all())
        ->assertJsonPath('data.group', 'Sports');

    Bus::assertDispatched(
        AddItemsToCustomPlaylist::class,
        fn (AddItemsToCustomPlaylist $job) => $job->customPlaylistId === $this->playlist->id
            && $job->itemIds === $channels->pluck('id')->all()
            && $job->data === ['mode' => 'select', 'category' => 'Sports'],
    );
});

it('rejects channel_number when attaching more than one channel', function () {
    Bus::fake();

    $channels = Channel::factory()->for($this->user)->count(2)->create();

    $this->withToken($this->token)
        ->postJson("/custom-playlist/{$this->playlist->uuid}/channels", [
            'ids' => $channels->pluck('id')->all(),
            'channel_number' => 5,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    Bus::assertNotDispatched(AddItemsToCustomPlaylist::class);
});

it('applies channel_number synchronously when attaching a single channel', function () {
    Bus::fake();

    $channel = Channel::factory()->for($this->user)->create();

    $this->withToken($this->token)
        ->postJson("/custom-playlist/{$this->playlist->uuid}/channels", [
            'ids' => [$channel->id],
            'channel_number' => 101,
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.channel_number_applied', 101);

    expect($this->playlist->channels()->find($channel->id)->pivot->channel_number)->toBe(101);

    Bus::assertDispatched(AddItemsToCustomPlaylist::class);
});

it('returns 404 attaching channels to a custom playlist belonging to another user', function () {
    Bus::fake();

    $other = User::factory()->create();
    $otherToken = $other->createToken('test')->plainTextToken;
    $channel = Channel::factory()->for($this->user)->create();

    $this->withToken($otherToken)
        ->postJson("/custom-playlist/{$this->playlist->uuid}/channels", [
            'ids' => [$channel->id],
        ])
        ->assertStatus(403)
        ->assertJsonPath('success', false);

    Bus::assertNotDispatched(AddItemsToCustomPlaylist::class);
});

it('returns 401 when unauthenticated', function () {
    $this->postJson("/custom-playlist/{$this->playlist->uuid}/channels", ['ids' => [1]])
        ->assertUnauthorized();
});

it('rejects channel ids belonging to another user', function () {
    Bus::fake();

    $other = User::factory()->create();
    $channel = Channel::factory()->for($other)->create();

    $this->withToken($this->token)
        ->postJson("/custom-playlist/{$this->playlist->uuid}/channels", [
            'ids' => [$channel->id],
        ])
        ->assertStatus(422);
});

it('queues a detach job', function () {
    Bus::fake();

    $channel = Channel::factory()->for($this->user)->create();
    $this->playlist->channels()->attach($channel->id);

    $this->withToken($this->token)
        ->deleteJson("/custom-playlist/{$this->playlist->uuid}/channels", [
            'ids' => [$channel->id],
        ])
        ->assertStatus(202)
        ->assertJsonPath('success', true);

    Bus::assertDispatched(
        DetachItemsFromCustomPlaylist::class,
        fn (DetachItemsFromCustomPlaylist $job) => $job->customPlaylistId === $this->playlist->id
            && $job->itemIds === [$channel->id],
    );
});

it('updates channel_number and sort for an attached channel', function () {
    $channel = Channel::factory()->for($this->user)->create();
    $this->playlist->channels()->attach($channel->id);

    $this->withToken($this->token)
        ->patchJson("/custom-playlist/{$this->playlist->uuid}/channels/{$channel->id}", [
            'channel_number' => 42,
            'sort' => 3,
        ])
        ->assertOk()
        ->assertJsonPath('data.channel_number', 42)
        ->assertJsonPath('data.sort', 3);

    $pivot = $this->playlist->channels()->find($channel->id)->pivot;
    expect($pivot->channel_number)->toBe(42)
        ->and((float) $pivot->sort)->toBe(3.0);
});

it('assigns and clears a channel group tag', function () {
    $channel = Channel::factory()->for($this->user)->create();
    $this->playlist->channels()->attach($channel->id);

    $this->withToken($this->token)
        ->patchJson("/custom-playlist/{$this->playlist->uuid}/channels/{$channel->id}", [
            'group' => 'Sports',
        ])
        ->assertOk()
        ->assertJsonPath('data.group', 'Sports');

    expect($this->playlist->groupTags()->count())->toBe(1);

    $this->withToken($this->token)
        ->patchJson("/custom-playlist/{$this->playlist->uuid}/channels/{$channel->id}", [
            'group' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.group', null);
});

it('returns 404 updating a channel not attached to the playlist', function () {
    $channel = Channel::factory()->for($this->user)->create();

    $this->withToken($this->token)
        ->patchJson("/custom-playlist/{$this->playlist->uuid}/channels/{$channel->id}", [
            'channel_number' => 1,
        ])
        ->assertStatus(404)
        ->assertJsonPath('success', false);
});

it('lists attached channels with pivot data', function () {
    $channel = Channel::factory()->for($this->user)->create();
    $this->playlist->channels()->attach($channel->id, ['channel_number' => 7]);

    $this->withToken($this->token)
        ->getJson("/custom-playlist/{$this->playlist->uuid}/channels")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $channel->id)
        ->assertJsonPath('data.0.channel_number', 7);
});

it('creates, lists, renames and reorders group tags', function () {
    $create = $this->withToken($this->token)
        ->postJson("/custom-playlist/{$this->playlist->uuid}/groups", ['name' => 'Sports'])
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Sports');

    $groupId = $create->json('data.id');

    $this->withToken($this->token)
        ->getJson("/custom-playlist/{$this->playlist->uuid}/groups")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Sports');

    $this->withToken($this->token)
        ->patchJson("/custom-playlist/{$this->playlist->uuid}/groups/{$groupId}", [
            'name' => 'Sports HD',
            'order_column' => 5,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Sports HD')
        ->assertJsonPath('data.order_column', 5);
});

it('returns 404 for an unknown group tag id', function () {
    $this->withToken($this->token)
        ->patchJson("/custom-playlist/{$this->playlist->uuid}/groups/99999", ['name' => 'X'])
        ->assertStatus(404);
});

it('returns 404 for a non-existent custom playlist', function () {
    $this->withToken($this->token)
        ->getJson('/custom-playlist/00000000-0000-0000-0000-000000000000/channels')
        ->assertStatus(404)
        ->assertJsonPath('success', false);
});
