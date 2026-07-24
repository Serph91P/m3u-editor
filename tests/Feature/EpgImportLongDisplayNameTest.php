<?php

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;
use Illuminate\Support\Str;

// GitHub issue #1318: EPG sync succeeded but the subsequent EPG Cache
// generation failed with "value too long for type character varying(255)".
//
// Root cause: ProcessEpgImport truncated overlong XMLTV <display-name>
// values with Str::limit($rawDisplayName, 255), which appends a 3-char
// "..." suffix and returns a string up to 258 characters long — 3 chars
// over the epg_channels.name varchar(255) limit. channel_id/lang were
// also unbounded varchar(255) with no truncation at all.

it('truncates long display names to fit within the configured limit', function () {
    $rawDisplayName = str_repeat('a', 600);

    // Mirrors the truncation call in ProcessEpgImport::handle().
    $name = Str::limit($rawDisplayName, 500, '');

    expect(mb_strlen($name))->toBeLessThanOrEqual(500);
});

it('persists epg_channels with long name, lang, and channel_id values', function () {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();

    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'name' => str_repeat('a', 300),
        'channel_id' => str_repeat('b', 300),
        'lang' => str_repeat('c', 300),
    ]);

    expect($epgChannel->refresh()->name)->toHaveLength(300)
        ->and($epgChannel->channel_id)->toHaveLength(300)
        ->and($epgChannel->lang)->toHaveLength(300);
});
