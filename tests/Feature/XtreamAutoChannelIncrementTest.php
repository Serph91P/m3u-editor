<?php

use App\Enums\PlaylistChannelId;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Notification::fake();
    Http::preventStrayRequests();
    config(['queue.default' => 'sync']);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'auto_channel_increment' => true,
        'channel_start' => 6000,
    ]);
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
});

it('Xtream API get_live_streams does not use auto channel increment before imported provider numbers', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'channel' => 12,
        'title' => 'Provider Number 12',
        'url' => 'http://example.com/live/12.ts',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => 987,
        'title' => 'Provider Number 987',
        'url' => 'http://example.com/live/987.ts',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([12, 987]);
});

it('Xtream API get_vod_streams does not use auto channel increment before imported provider numbers', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 1,
        'channel' => 55,
        'title' => 'Provider VOD 55',
        'url' => 'http://example.com/movie/55.mkv',
        'container_extension' => 'mkv',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 2,
        'channel' => 101,
        'title' => 'Provider VOD 101',
        'url' => 'http://example.com/movie/101.mkv',
        'container_extension' => 'mkv',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_vod_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([55, 101]);
});

it('Xtream API get_live_streams uses auto channel increment before imported provider numbers', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'channel' => null,
        'title' => 'Provider Number Not Provided',
        'url' => 'http://example.com/live/12.ts',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => null,
        'title' => 'Provider Number Not Provided',
        'url' => 'http://example.com/live/987.ts',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([6000, 6001]);
});

it('Xtream API get_vod_streams uses auto channel increment before imported provider numbers', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 1,
        'channel' => null,
        'title' => 'Provider VOD Not Provided',
        'url' => 'http://example.com/movie/55.mkv',
        'container_extension' => 'mkv',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 2,
        'channel' => null,
        'title' => 'Provider VOD Not Provided',
        'url' => 'http://example.com/movie/101.mkv',
        'container_extension' => 'mkv',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_vod_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([6000, 6001]);
});

it('Xtream API get_live_streams overrides imported provider numbers when force channel numbering is enabled', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 7000,
    ]);

    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'channel' => 12,
        'title' => 'Provider Number 12',
        'url' => 'http://example.com/live/12.ts',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => 987,
        'title' => 'Provider Number 987',
        'url' => 'http://example.com/live/987.ts',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([7000, 7001]);
});

it('Xtream API get_vod_streams overrides imported provider numbers when force channel numbering is enabled', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 7000,
    ]);

    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 1,
        'channel' => 55,
        'title' => 'Provider VOD 55',
        'url' => 'http://example.com/movie/55.mkv',
        'container_extension' => 'mkv',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'sort' => 2,
        'channel' => 101,
        'title' => 'Provider VOD 101',
        'url' => 'http://example.com/movie/101.mkv',
        'container_extension' => 'mkv',
    ]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_vod_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(2)
        ->and(array_column($streams, 'num'))->toBe([7000, 7001]);
});

it('keeps mixed output numbering in Xtream live listings', function (bool $force, bool $filtered) {
    $this->playlist->update([
        'include_vod_in_m3u' => true,
        'force_channel_numbering' => $force,
        'channel_start' => 100,
        'id_channel_by' => PlaylistChannelId::Number,
    ]);

    $channels = collect([false, true, false])->map(fn (bool $isVod, int $index) => Channel::factory()
        ->for($this->user)->for($this->playlist)->for($this->group)->create([
            'enabled' => true,
            'is_vod' => $isVod,
            'sort' => $index + 1,
            'channel' => $force ? 500 + $index : null,
            'title' => 'Mixed '.$index,
        ]));

    $url = '/player_api.php?'.http_build_query([
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'action' => 'get_live_streams',
        'category_id' => $filtered ? $this->group->id : null,
    ]);
    $streams = json_decode($this->get($url)->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($streams, 'num', 'stream_id'))->toBe([
        $channels[0]->id => 100,
        $channels[2]->id => 102,
    ])->and(array_column($streams, 'epg_channel_id'))->toBe(['100', '102']);
})->with([true, false])->with([true, false]);

it('keeps mixed output numbering in Xtream VOD listings', function (bool $filtered) {
    $this->playlist->update([
        'include_vod_in_m3u' => true,
        'force_channel_numbering' => true,
        'channel_start' => 100,
    ]);
    $channels = collect([false, true, false])->map(fn (bool $isVod, int $index) => Channel::factory()
        ->for($this->user)->for($this->playlist)->for($this->group)->create([
            'enabled' => true,
            'is_vod' => $isVod,
            'sort' => $index + 1,
            'channel' => null,
        ]));
    $streams = json_decode($this->get('/player_api.php?'.http_build_query([
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'action' => 'get_vod_streams',
        'category_id' => $filtered ? $this->group->id : null,
    ]))->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($streams, 'num', 'stream_id'))->toBe([$channels[1]->id => 101]);
})->with([true, false]);

it('keeps explicit provider numbers when mixed output numbering is not positional', function () {
    $this->playlist->update([
        'include_vod_in_m3u' => true,
        'auto_channel_increment' => false,
        'force_channel_numbering' => false,
        'id_channel_by' => PlaylistChannelId::TvgId,
    ]);
    foreach ([false, true, false] as $index => $isVod) {
        Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
            'enabled' => true,
            'is_vod' => $isVod,
            'sort' => $index + 1,
            'channel' => 20 + $index,
            'channel_minor' => 1,
            'stream_id' => 'provider.'.$index,
        ]);
    }
    $streams = json_decode($this->get('/player_api.php?'.http_build_query([
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'action' => 'get_live_streams',
    ]))->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($streams, 'num'))->toBe([20, 22])
        ->and(array_column($streams, 'epg_channel_id'))->toBe(['provider.0', 'provider.2']);
});

it('M3U output overrides imported provider numbers when force channel numbering is enabled', function () {
    $this->playlist->update([
        'force_channel_numbering' => true,
        'channel_start' => 7000,
    ]);

    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'channel' => 12,
        'title' => 'Provider Number 12',
        'url' => 'http://example.com/live/12.ts',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => 987,
        'title' => 'Provider Number 987',
        'url' => 'http://example.com/live/987.ts',
    ]);

    $response = $this->get("/{$this->playlist->uuid}/playlist.m3u");

    $response->assertStatus(200);

    expect($response->streamedContent())
        ->toContain('tvg-chno="7000"')
        ->toContain('tvg-chno="7001"')
        ->not->toContain('tvg-chno="12"')
        ->not->toContain('tvg-chno="987"');
});
