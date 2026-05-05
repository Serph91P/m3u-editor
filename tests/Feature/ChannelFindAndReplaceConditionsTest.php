<?php

use App\Jobs\ChannelFindAndReplace;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

/**
 * Build a probe payload with overridable video/audio attributes so each
 * test can exercise exactly the resolution/codec it cares about.
 */
function probePayload(array $video = [], array $audio = []): array
{
    return [
        ['stream' => array_merge([
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'width' => 1920,
            'height' => 1080,
            'bit_rate' => '4000000',
            'avg_frame_rate' => '25/1',
            'profile' => 'High',
        ], $video)],
        ['stream' => array_merge([
            'codec_type' => 'audio',
            'codec_name' => 'aac',
            'channels' => 2,
            'sample_rate' => '48000',
        ], $audio)],
    ];
}

function dispatchFindReplace(Collection $channels, array $overrides = []): void
{
    $job = new ChannelFindAndReplace(
        user_id: $channels->first()->user_id,
        use_regex: $overrides['use_regex'] ?? true,
        column: $overrides['column'] ?? 'name',
        find_replace: $overrides['find_replace'] ?? '\s+(HD|UHD|4K|FHD)$',
        replace_with: $overrides['replace_with'] ?? ' FHD',
        channels: $channels,
        silent: true,
        conditions: $overrides['conditions'] ?? [],
        conditions_match_mode: $overrides['conditions_match_mode'] ?? 'all',
        require_probe_data: $overrides['require_probe_data'] ?? false,
    );
    $job->handle();
}

it('updates channels matching a probe-data condition on resolution', function () {
    $matching = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Some Channel HD',
        'stream_stats' => probePayload(['width' => 1920, 'height' => 1080]),
    ]);
    $other = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Other Channel HD',
        'stream_stats' => probePayload(['width' => 1280, 'height' => 720]),
    ]);

    dispatchFindReplace(
        Channel::query()->whereIn('id', [$matching->id, $other->id])->get(),
        [
            'conditions' => [['field' => 'video.resolution', 'op' => '=', 'value' => '1920x1080']],
        ],
    );

    expect($matching->fresh()->name_custom)->toBe('Some Channel FHD');
    expect($other->fresh()->name_custom)->toBeNull();
});

it('skips channels without probe data when conditions are configured', function () {
    $unprobed = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Unprobed Channel HD',
        'stream_stats' => null,
    ]);

    dispatchFindReplace(
        Channel::query()->whereKey($unprobed->id)->get(),
        [
            'conditions' => [['field' => 'video.resolution', 'op' => '=', 'value' => '1920x1080']],
            'require_probe_data' => true,
        ],
    );

    expect($unprobed->fresh()->name_custom)->toBeNull();
});

it('also skips unprobed channels when require_probe_data is false', function () {
    // Without probe data nothing can satisfy a condition, so the row must
    // still be skipped — require_probe_data only changes whether we may
    // surface a warning later, not the outcome.
    $unprobed = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Unprobed Channel HD',
        'stream_stats' => null,
    ]);

    dispatchFindReplace(
        Channel::query()->whereKey($unprobed->id)->get(),
        [
            'conditions' => [['field' => 'video.resolution', 'op' => '=', 'value' => '1920x1080']],
            'require_probe_data' => false,
        ],
    );

    expect($unprobed->fresh()->name_custom)->toBeNull();
});

it('matches when any condition holds in OR mode', function () {
    $hd = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'HD Stream UHD',
        'stream_stats' => probePayload(['width' => 1280, 'height' => 720]),
    ]);
    $fhd = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'FHD Stream UHD',
        'stream_stats' => probePayload(['width' => 1920, 'height' => 1080]),
    ]);
    $sd = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'SD Stream UHD',
        'stream_stats' => probePayload(['width' => 720, 'height' => 576]),
    ]);

    dispatchFindReplace(
        Channel::query()->whereIn('id', [$hd->id, $fhd->id, $sd->id])->get(),
        [
            'find_replace' => '\s+UHD$',
            'replace_with' => '',
            'conditions_match_mode' => 'any',
            'conditions' => [
                ['field' => 'video.resolution', 'op' => '=', 'value' => '1920x1080'],
                ['field' => 'video.resolution', 'op' => '=', 'value' => '1280x720'],
            ],
        ],
    );

    expect($hd->fresh()->name_custom)->toBe('HD Stream');
    expect($fhd->fresh()->name_custom)->toBe('FHD Stream');
    expect($sd->fresh()->name_custom)->toBeNull();
});

it('supports in / not_in operators against codec_name', function () {
    $hevc = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Movie HD',
        'stream_stats' => probePayload(['codec_name' => 'hevc']),
    ]);
    $h264 = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Movie HD',
        'stream_stats' => probePayload(['codec_name' => 'h264']),
    ]);

    dispatchFindReplace(
        Channel::query()->whereIn('id', [$hevc->id, $h264->id])->get(),
        [
            'find_replace' => 'HD',
            'replace_with' => 'HEVC',
            'use_regex' => false,
            'conditions' => [
                ['field' => 'video.codec_name', 'op' => 'in', 'value' => ['hevc', 'h265']],
            ],
        ],
    );

    expect($hevc->fresh()->name_custom)->toBe('Movie HEVC');
    expect($h264->fresh()->name_custom)->toBeNull();
});

it('updates every channel when no conditions are configured (regression)', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Channel HD',
        'stream_stats' => null,
    ]);

    dispatchFindReplace(
        Channel::query()->whereKey($channel->id)->get(),
        ['find_replace' => 'HD', 'replace_with' => 'FHD', 'use_regex' => false],
    );

    expect($channel->fresh()->name_custom)->toBe('Channel FHD');
});
