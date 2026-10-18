<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * #1623 - the per-channel EPG shift (tvg_shift) is applied to the generated XMLTV and
 * the in-app EPG viewer, so the Xtream EPG actions (used by M3U TV) must apply it too.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => 'shift_user',
        'password' => 'shift_pass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($auth->id);

    $this->start = Carbon::now()->addMinutes(30)->startOfSecond();

    $epg = Epg::factory()->for($this->user)->create(['is_cached' => true]);
    $epgChannel = EpgChannel::factory()->for($this->user)->for($epg)->create(['channel_id' => 'shift.channel']);
    $this->epg = $epg;

    $this->makeChannel = fn (?string $shift) => Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->create([
            'enabled' => true,
            'epg_channel_id' => $epgChannel->id,
            'group_id' => null,
            'tvg_shift' => $shift,
        ]);

    seedEpgProgrammeCache($epg, [
        ['shift.channel', [
            'id' => 'prog-1',
            'title' => 'Evening News',
            'subtitle' => '',
            'desc' => '',
            'start' => $this->start->format('Y-m-d H:i:s'),
            'stop' => $this->start->copy()->addHour()->format('Y-m-d H:i:s'),
            'lang' => 'en',
        ]],
    ]);

    $this->xtreamUrl = fn (string $action, array $params = []) => route('xtream.api.player').'?'.http_build_query(array_merge([
        'username' => 'shift_user',
        'password' => 'shift_pass',
        'action' => $action,
    ], $params));
});

it('shifts get_short_epg and get_simple_data_table listings by the channel tvg_shift', function (string $action, string $shift, int $hours) {
    $channel = ($this->makeChannel)($shift);

    $listings = $this->getJson(($this->xtreamUrl)($action, ['stream_id' => (string) $channel->id]))
        ->assertOk()
        ->json('epg_listings');

    $programme = collect($listings)->first(fn ($listing) => $listing['channel_id'] === 'shift.channel');

    expect((int) $programme['start_timestamp'])->toBe($this->start->copy()->addHours($hours)->timestamp)
        ->and((int) $programme['stop_timestamp'])->toBe($this->start->copy()->addHour()->addHours($hours)->timestamp);
})->with([
    'short epg, positive' => ['get_short_epg', '2', 2],
    'short epg, negative' => ['get_short_epg', '-1', -1],
    'data table, positive' => ['get_simple_data_table', '2', 2],
]);

it('shifts get_epg_batch listings by the channel tvg_shift', function () {
    $channel = ($this->makeChannel)('2');

    $listings = $this->getJson(($this->xtreamUrl)('get_epg_batch', ['stream_ids' => (string) $channel->id]))
        ->assertOk()
        ->json("{$channel->id}.epg_listings") ?? [];

    $programme = collect($listings)->first(fn ($listing) => base64_decode($listing['title']) === 'Evening News');

    expect($programme)->not->toBeNull()
        ->and((int) $programme['start_timestamp'])->toBe($this->start->copy()->addHours(2)->timestamp);
});

it('leaves times untouched when the channel has no tvg_shift', function () {
    $channel = ($this->makeChannel)(null);

    $programme = $this->getJson(($this->xtreamUrl)('get_short_epg', ['stream_id' => (string) $channel->id]))
        ->assertOk()
        ->json('epg_listings.0');

    expect((int) $programme['start_timestamp'])->toBe($this->start->timestamp);
});

it('includes earlier-dated programmes that a positive shift moves into today', function () {
    Carbon::setTestNow(Carbon::today()->setTime(1, 0));
    $channel = ($this->makeChannel)('3');

    // Aired 22:00-23:30 yesterday, so with +3 it is on now (01:00-02:30)
    $yesterdayStart = Carbon::yesterday()->setTime(22, 0);
    seedEpgProgrammeCache($this->epg, [
        ['shift.channel', [
            'id' => 'late-show',
            'title' => 'Late Show',
            'start' => $yesterdayStart->format('Y-m-d H:i:s'),
            'stop' => $yesterdayStart->copy()->addMinutes(90)->format('Y-m-d H:i:s'),
        ]],
    ]);

    $programme = $this->getJson(($this->xtreamUrl)('get_short_epg', ['stream_id' => (string) $channel->id]))
        ->assertOk()
        ->json('epg_listings.0');

    expect($programme['title'])->toBe('Late Show')
        ->and((int) $programme['start_timestamp'])->toBe($yesterdayStart->copy()->addHours(3)->timestamp);
});

it('adds shifted-in earlier programmes to get_epg_batch only for positively shifted channels', function () {
    $shifted = ($this->makeChannel)('2');
    $unshifted = ($this->makeChannel)(null);

    $today = Carbon::today();
    seedEpgProgrammeCache($this->epg, [
        // Still ends before today once shifted +2, so it stays out
        ['shift.channel', [
            'id' => 'early-show',
            'title' => 'Early Show',
            'start' => $today->copy()->subDay()->setTime(19, 0)->format('Y-m-d H:i:s'),
            'stop' => $today->copy()->subDay()->setTime(20, 0)->format('Y-m-d H:i:s'),
        ]],
        // Shifted +2 it runs 01:00-02:30 today
        ['shift.channel', [
            'id' => 'late-show',
            'title' => 'Late Show',
            'start' => $today->copy()->subDay()->setTime(23, 0)->format('Y-m-d H:i:s'),
            'stop' => $today->copy()->setTime(0, 30)->format('Y-m-d H:i:s'),
        ]],
    ]);

    $response = $this->getJson(($this->xtreamUrl)('get_epg_batch', [
        'stream_ids' => "{$shifted->id},{$unshifted->id}",
        'date' => $today->format('Y-m-d'),
    ]))->assertOk();

    $titles = fn (int $streamId) => collect($response->json("{$streamId}.epg_listings"))
        ->map(fn ($listing) => base64_decode($listing['title']));

    $lateShow = collect($response->json("{$shifted->id}.epg_listings"))
        ->first(fn ($listing) => base64_decode($listing['title']) === 'Late Show');

    expect($lateShow)->not->toBeNull()
        ->and((int) $lateShow['start_timestamp'])->toBe($today->copy()->setTime(1, 0)->timestamp)
        ->and($titles($shifted->id))->not->toContain('Early Show')
        ->and($titles($unshifted->id))->not->toContain('Late Show');
});
