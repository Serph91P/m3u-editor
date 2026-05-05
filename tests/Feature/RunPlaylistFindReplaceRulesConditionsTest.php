<?php

use App\Jobs\RunPlaylistFindReplaceRules;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    $this->user = User::factory()->create();
});

it('only applies a saved channel rule to channels matching the probe condition', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'find_replace_rules' => [
            [
                'enabled' => true,
                'name' => 'Tag FHD by resolution',
                'target' => 'channels',
                'column' => 'name',
                'use_regex' => true,
                'find_replace' => '\s+(HD|UHD|4K|FHD)$',
                'replace_with' => ' FHD',
                'conditions_enabled' => true,
                'conditions_match_mode' => 'all',
                'require_probe_data' => true,
                'conditions' => [
                    ['field' => 'video.resolution', 'op' => '=', 'value' => '1920x1080'],
                ],
            ],
        ],
    ]);

    $matching = Channel::factory()->for($this->user)->for($playlist)->create([
        'name' => 'Channel A HD',
        'is_vod' => false,
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'codec_name' => 'h264']],
        ],
    ]);
    $tooSmall = Channel::factory()->for($this->user)->for($playlist)->create([
        'name' => 'Channel B HD',
        'is_vod' => false,
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'width' => 1280, 'height' => 720, 'codec_name' => 'h264']],
        ],
    ]);
    $unprobed = Channel::factory()->for($this->user)->for($playlist)->create([
        'name' => 'Channel C HD',
        'is_vod' => false,
        'stream_stats' => null,
    ]);

    (new RunPlaylistFindReplaceRules($playlist))->handle();

    expect($matching->fresh()->name_custom)->toBe('Channel A FHD');
    expect($tooSmall->fresh()->name_custom)->toBeNull();
    expect($unprobed->fresh()->name_custom)->toBeNull();
});

it('treats conditions_enabled = false as the previous unconditional behaviour', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'find_replace_rules' => [
            [
                'enabled' => true,
                'name' => 'Strip HD suffix',
                'target' => 'channels',
                'column' => 'name',
                'use_regex' => false,
                'find_replace' => ' HD',
                'replace_with' => '',
                'conditions_enabled' => false,
                'conditions' => [
                    // Present but ignored since flag is off.
                    ['field' => 'video.resolution', 'op' => '=', 'value' => '1920x1080'],
                ],
            ],
        ],
    ]);

    $unprobed = Channel::factory()->for($this->user)->for($playlist)->create([
        'name' => 'Channel HD',
        'is_vod' => false,
        'stream_stats' => null,
    ]);

    (new RunPlaylistFindReplaceRules($playlist))->handle();

    expect($unprobed->fresh()->name_custom)->toBe('Channel');
});
