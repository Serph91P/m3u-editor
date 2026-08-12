<?php

use App\Enums\PlaylistChannelId;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('prefers the user-edited stream ID over the internally generated source ID for the default TVG ID mode', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'source_id' => 'b75ee65fc008712a36285e4e6db0e43c',
        'stream_id_custom' => 'KOMO-DT',
        'stream_id' => 'ignored-fallback',
    ]);

    expect($channel->resolveTvgId(PlaylistChannelId::TvgId))->toBe('KOMO-DT');
});

it('falls back to the source ID when no custom stream ID is set', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'source_id' => '76704',
        'stream_id_custom' => null,
        'stream_id' => 'ignored-fallback',
    ]);

    expect($channel->resolveTvgId(PlaylistChannelId::TvgId))->toBe('76704');
});

it('falls back to the raw stream ID when neither custom nor source ID is set', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'source_id' => null,
        'stream_id_custom' => null,
        'stream_id' => 'raw-stream-id',
    ]);

    expect($channel->resolveTvgId(PlaylistChannelId::TvgId))->toBe('raw-stream-id');
});

it('uses the channel number for the Number mode', function () {
    $channel = Channel::factory()->for($this->playlist)->create();

    expect($channel->resolveTvgId(PlaylistChannelId::Number, 42))->toBe(42);
});

it('uses the channel database ID for the ChannelId mode', function () {
    $channel = Channel::factory()->for($this->playlist)->create();

    expect($channel->resolveTvgId(PlaylistChannelId::ChannelId))->toBe($channel->id);
});
