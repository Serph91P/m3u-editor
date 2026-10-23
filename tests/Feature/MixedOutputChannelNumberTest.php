<?php

use App\Enums\PlaylistChannelId;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Notification::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['queue.default' => 'sync']);
});

it('preserves each live channel identity across mixed output routes', function (string $context, string $mode, bool $includeVod) {
    $user = User::factory()->create();
    $settings = [
        'include_vod_in_m3u' => $includeVod,
        'force_channel_numbering' => $mode === 'force',
        'auto_channel_increment' => true,
        'channel_start' => 100,
        'sort_by_channel_number' => false,
        'id_channel_by' => PlaylistChannelId::Number,
        'dummy_epg' => false,
    ];
    $source = Playlist::factory()->for($user)->create($settings);
    $output = $context === 'playlist' ? $source : CustomPlaylist::factory()->for($user)->create($settings);
    $epg = Epg::factory()->for($user)->create(['is_cached' => false]);
    $start = now()->startOfDay()->format('YmdHis O');
    $stop = now()->addDay()->startOfDay()->format('YmdHis O');
    $sourceXml = '<?xml version="1.0"?><tv>';
    $excludedGroup = Group::factory()->for($user)->for($source, 'playlist')->create(['name' => 'Excluded', 'sort_order' => 0]);
    $excluded = Channel::factory()->for($user)->for($source, 'playlist')->for($excludedGroup, 'group')->create([
        'enabled' => $context === 'alias',
        'is_vod' => false,
        'group' => 'Excluded',
        'sort' => 0,
        'channel' => null,
    ]);
    if ($output instanceof CustomPlaylist) {
        $output->channels()->attach($excluded->id, ['sort' => 0]);
    }
    $channels = [];
    foreach ([false, true, false] as $index => $isVod) {
        $group = Group::factory()->for($user)->for($source, 'playlist')->create(['name' => 'Group '.$index, 'sort_order' => $index + 1]);
        $epgChannel = $isVod ? null : EpgChannel::factory()->for($user)->for($epg)->create([
            'channel_id' => 'source.'.$index,
            'display_name' => 'Channel '.$index,
            'lang' => 'en',
        ]);
        $channel = Channel::factory()->for($user)->for($source, 'playlist')->for($group)->create([
            'enabled' => true,
            'is_vod' => $isVod,
            'group' => 'Group '.$index,
            'sort' => $index + 1,
            'channel' => $mode === 'auto' ? null : 500 + $index,
            'channel_minor' => $mode === 'explicit' ? $index + 1 : null,
            'epg_channel_id' => $epgChannel?->id,
            'title' => 'Channel '.$index,
            'title_custom' => null,
            'name' => 'Channel '.$index,
            'url' => 'http://example.test/stream/'.$index,
        ]);
        if ($output instanceof CustomPlaylist) {
            $output->channels()->attach($channel->id, [
                'sort' => $index + 1,
                'channel_number' => $mode === 'explicit' ? 700 + $index : null,
                'channel_minor' => $mode === 'explicit' ? $index + 2 : null,
            ]);
        }
        if (! $isVod) {
            $sourceXml .= '<channel id="source.'.$index.'"><display-name>Channel '.$index.'</display-name></channel>';
            $sourceXml .= '<programme channel="source.'.$index.'" start="'.$start.'" stop="'.$stop.'"><title>Show '.$index.'</title></programme>';
        }
        $channels[] = $channel;
    }
    Storage::disk('local')->put($epg->file_path, gzencode($sourceXml.'</tv>'));

    $username = $user->name;
    $password = $output->uuid;
    if ($context === 'alias') {
        $output = PlaylistAlias::create([
            'name' => 'Synthetic alias',
            'uuid' => fake()->uuid(),
            'user_id' => $user->id,
            'custom_playlist_id' => $output->id,
            'group_filter' => [
                'selected_groups' => ['Group 0', 'Group 2'],
                'selected_vod_groups' => ['Group 1'],
            ],
            'username' => 'synthetic_alias',
            'password' => 'synthetic_password',
            'xtream_config' => null,
        ]);
        $username = $output->username;
        $password = $output->password;
    }

    $auth = '?'.http_build_query(['username' => $username, 'password' => $password]);
    $m3u = $this->get("/{$output->uuid}/playlist.m3u{$auth}")->assertOk()->streamedContent();
    $lineup = json_decode($this->get("/{$output->uuid}/hdhr/lineup.json{$auth}")->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    $xmlResponse = $this->get("/{$output->uuid}/epg.xml{$auth}")->assertOk()->assertHeader('X-EPG-Cache', 'MISS');
    $xml = simplexml_load_string($xmlResponse->streamedContent());
    $gzipResponse = $this->get("/{$output->uuid}/epg.xml.gz{$auth}")->assertOk();
    $gzipXml = simplexml_load_string(gzdecode($gzipResponse->streamedContent()));
    $url = '/player_api.php?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'get_live_streams',
    ]);
    $streams = json_decode($this->get($url)->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($streams, 'stream_id'))->toBe([$channels[0]->id, $channels[2]->id]);

    foreach ($streams as $stream) {
        $index = $stream['stream_id'] === $channels[0]->id ? 0 : 2;
        $expected = $mode === 'explicit'
            ? (string) (($context === 'playlist' ? 500 : 700) + $index).'.'.($index + ($context === 'playlist' ? 1 : 2))
            : (string) (100 + ($includeVod ? $index : intdiv($index, 2)));
        expect($stream['epg_channel_id'])->toBe($expected)
            ->and($stream['num'])->toBe((int) $expected);
        preg_match('/^#EXTINF:[^\r\n]*,Channel '.$index.'$/m', $m3u, $entry);
        expect($entry)->toHaveCount(1)
            ->and($entry[0])->toContain('tvg-id="'.$expected.'"', 'tvg-chno="'.$expected.'"');
        $guide = collect($lineup)->firstWhere('GuideName', 'Channel '.$index);
        expect($guide['GuideNumber'])->toBe($expected);
        foreach ([$xml, $gzipXml] as $document) {
            expect($document->xpath('/tv/channel[@id="'.$expected.'"]/display-name')[0]->__toString())->toBe('Channel '.$index)
                ->and($document->xpath('/tv/programme[@channel="'.$expected.'"]/title')[0]->__toString())->toBe('Show '.$index);
        }
        $filtered = json_decode($this->get($url.'&category_id='.$stream['category_id'])->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($filtered, 'epg_channel_id', 'stream_id'))->toBe([$stream['stream_id'] => $expected]);
    }
    Http::assertNothingSent();
})->with(['playlist', 'custom', 'alias'])->with(['force', 'auto', 'explicit'])->with([true, false]);
