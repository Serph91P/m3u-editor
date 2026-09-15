<?php

/**
 * Regression coverage for MergedPlaylist-scoped DVR resolution in XtreamApiController.
 *
 * Before this fix:
 *   1. `resolveDvrSettingIds()` pulled every source playlist_id from the
 *      merged_playlist_playlist pivot with no regard for the per-source
 *      include_live/include_vod/include_series toggles, so a source Playlist
 *      fully excluded from the merge (all three toggles off) still had its
 *      DvrSetting/recordings listed through the wrapper.
 *   2. `resolveDvrSettingForWrite()` returned the channel's source-Playlist
 *      DvrSetting unconditionally, even when disabled, short-circuiting
 *      before the wrapper's own enabled DvrSetting was ever tried.
 *   3. `getDvrStorage()` summed multiple DvrSetting quotas with sum(), which
 *      silently turned an unlimited (null/0) quota into 0 once combined with
 *      another source's real quota, instead of propagating "unlimited".
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->andReturn(true);
    $proxy->shouldReceive('cleanupDvrBroadcast')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);
});

function mergedPlaylistDvrActionUrl(string $username, string $password, string $action, array $extra = []): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
        ...$extra,
    ]);
}

it('excludes DVR recordings from a source playlist fully excluded from the merge', function () {
    $included = Playlist::factory()->for($this->user)->create();
    $excluded = Playlist::factory()->for($this->user)->create();
    $group = Group::factory()->for($this->user)->create();

    $includedChannel = Channel::factory()->for($included)->for($group)->create(['enabled' => true]);
    $excludedChannel = Channel::factory()->for($excluded)->for($group)->create(['enabled' => true]);

    $includedSetting = DvrSetting::factory()->enabled()->for($this->user)->for($included)->create();
    $excludedSetting = DvrSetting::factory()->enabled()->for($this->user)->for($excluded)->create();

    $includedRecording = DvrRecording::factory()
        ->for($includedSetting, 'dvrSetting')->for($this->user)->for($includedChannel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);
    $excludedRecording = DvrRecording::factory()
        ->for($excludedSetting, 'dvrSetting')->for($this->user)->for($excludedChannel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($included->id, ['include_live' => true, 'include_vod' => true, 'include_series' => true]);
    $merged->playlists()->attach($excluded->id, ['include_live' => false, 'include_vod' => false, 'include_series' => false]);

    $response = $this->postJson(
        mergedPlaylistDvrActionUrl($this->user->name, $merged->uuid, 'get_dvr_recordings')
    )->assertOk();

    $uuids = collect($response->json())->pluck('uuid');

    expect($uuids)->toContain($includedRecording->uuid)
        ->and($uuids)->not->toContain($excludedRecording->uuid);
});

it('falls back to the wrapper DvrSetting when the channel source playlist DvrSetting is disabled', function () {
    $source = Playlist::factory()->for($this->user)->create();
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($source)->for($group)->create(['enabled' => true]);

    // Source playlist has a disabled DvrSetting; the wrapper has its own enabled one.
    DvrSetting::factory()->for($this->user)->for($source)->create(['enabled' => false]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($source->id, ['include_live' => true, 'include_vod' => true, 'include_series' => true]);
    DvrSetting::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $this->user->id,
        'enabled' => true,
    ]);

    $response = $this->postJson(
        mergedPlaylistDvrActionUrl($this->user->name, $merged->uuid, 'schedule_dvr', [
            'channel_id' => $channel->id,
            'title' => 'Test Recording',
            'start_time' => now()->addMinute()->toIso8601String(),
            'end_time' => now()->addHour()->toIso8601String(),
        ])
    );

    $response->assertOk();
    expect($response->json('success'))->toBeTrue();
});

it('reports unlimited DVR storage quota when one of several source settings has no quota', function () {
    $sourceA = Playlist::factory()->for($this->user)->create();
    $sourceB = Playlist::factory()->for($this->user)->create();
    $group = Group::factory()->for($this->user)->create();

    Channel::factory()->for($sourceA)->for($group)->create(['enabled' => true]);
    Channel::factory()->for($sourceB)->for($group)->create(['enabled' => true]);

    DvrSetting::factory()->enabled()->for($this->user)->for($sourceA)->create(['global_disk_quota_gb' => 10]);
    DvrSetting::factory()->enabled()->for($this->user)->for($sourceB)->create(['global_disk_quota_gb' => null]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($sourceA->id, ['include_live' => true, 'include_vod' => true, 'include_series' => true]);
    $merged->playlists()->attach($sourceB->id, ['include_live' => true, 'include_vod' => true, 'include_series' => true]);

    $response = $this->postJson(
        mergedPlaylistDvrActionUrl($this->user->name, $merged->uuid, 'get_dvr_storage')
    )->assertOk();

    expect($response->json('quota_bytes'))->toBeNull();
});
