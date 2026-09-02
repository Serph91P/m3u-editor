<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

/**
 * Builds a gzipped XMLTV document whose <programme> elements are emitted in the
 * given order so tests can exercise both contiguous and interleaved channel
 * layouts, then runs the real cache generation.
 *
 * @param  list<array{channel: string, title: string, startHour: int}>  $programmes
 */
function cacheXmltvForIndex(array $channelIds, array $programmes): array
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create(['url' => 'https://example.com/index.xml']);

    foreach ($channelIds as $channelId) {
        $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
            'channel_id' => $channelId,
            'display_name' => $channelId,
        ]);
        Channel::factory()->for($user)->for($playlist)->create([
            'enabled' => true,
            'is_vod' => false,
            'epg_channel_id' => $epgChannel->id,
        ]);
    }

    $channelXml = collect($channelIds)
        ->map(fn ($id) => "  <channel id=\"{$id}\"><display-name>{$id}</display-name></channel>")
        ->implode("\n");

    $programmeXml = collect($programmes)
        ->map(function ($p) {
            $start = now()->startOfDay()->addHours($p['startHour'])->format('YmdHis O');
            $stop = now()->startOfDay()->addHours($p['startHour'] + 1)->format('YmdHis O');

            return "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"{$p['channel']}\">\n".
                "    <title>{$p['title']}</title>\n".
                '  </programme>';
        })
        ->implode("\n");

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv>\n{$channelXml}\n{$programmeXml}\n</tv>";
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    return ['epg' => $epg, 'date' => now()->format('Y-m-d')];
}

it('writes a seekable offset index alongside the programmes file and removes the sidecar', function () {
    ['epg' => $epg, 'date' => $date] = cacheXmltvForIndex(
        ['channel.a', 'channel.b'],
        [
            ['channel' => 'channel.a', 'title' => 'A One', 'startHour' => 1],
            ['channel' => 'channel.a', 'title' => 'A Two', 'startHour' => 2],
            ['channel' => 'channel.b', 'title' => 'B One', 'startHour' => 1],
        ],
    );

    $dir = "epg-cache/{$epg->uuid}/v2";

    expect(Storage::disk('local')->exists("{$dir}/programmes-{$date}.jsonl.index.json"))->toBeTrue()
        ->and(Storage::disk('local')->exists("{$dir}/programmes-{$date}.jsonl.ranges.tmp"))->toBeFalse();

    $index = json_decode(Storage::disk('local')->get("{$dir}/programmes-{$date}.jsonl.index.json"), true);
    $jsonl = Storage::disk('local')->get("{$dir}/programmes-{$date}.jsonl");

    expect($index['v'])->toBe(1)
        ->and(array_keys($index['channels']))->toEqualCanonicalizing(['channel.a', 'channel.b'])
        // channel.a's two consecutive lines collapse into a single contiguous run
        ->and($index['channels']['channel.a'])->toHaveCount(1);

    // Every recorded range points at exactly the right slice of the file.
    foreach ($index['channels'] as $channelId => $runs) {
        foreach ($runs as [$offset, $length]) {
            $slice = substr($jsonl, $offset, $length);
            foreach (explode("\n", trim($slice)) as $line) {
                expect(json_decode($line, true)['channel'])->toBe($channelId);
            }
        }
    }
});

it('returns only the requested channel when reading through the index', function () {
    ['epg' => $epg, 'date' => $date] = cacheXmltvForIndex(
        ['channel.a', 'channel.b'],
        [
            ['channel' => 'channel.a', 'title' => 'A One', 'startHour' => 1],
            ['channel' => 'channel.b', 'title' => 'B One', 'startHour' => 1],
            ['channel' => 'channel.b', 'title' => 'B Two', 'startHour' => 3],
        ],
    );

    $programmes = app(EpgCacheService::class)->getCachedProgrammes($epg, $date, ['channel.b']);

    expect(array_keys($programmes))->toBe(['channel.b'])
        ->and(collect($programmes['channel.b'])->pluck('title')->all())->toBe(['B One', 'B Two']);
});

it('resolves non-contiguous channel runs from an interleaved source file', function () {
    ['epg' => $epg, 'date' => $date] = cacheXmltvForIndex(
        ['channel.a', 'channel.b'],
        [
            ['channel' => 'channel.b', 'title' => 'B One', 'startHour' => 1],
            ['channel' => 'channel.a', 'title' => 'A One', 'startHour' => 1],
            ['channel' => 'channel.b', 'title' => 'B Two', 'startHour' => 2],
            ['channel' => 'channel.a', 'title' => 'A Two', 'startHour' => 2],
        ],
    );

    $index = json_decode(
        Storage::disk('local')->get("epg-cache/{$epg->uuid}/v2/programmes-{$date}.jsonl.index.json"),
        true,
    );

    // Interleaved layout means each channel has two separate runs.
    expect($index['channels']['channel.b'])->toHaveCount(2);

    $service = app(EpgCacheService::class);

    expect(collect($service->getCachedProgrammes($epg, $date, ['channel.b'])['channel.b'])->pluck('title')->all())
        ->toBe(['B One', 'B Two']);

    // getCachedProgrammesRange streams via the same index path.
    $range = $service->getCachedProgrammesRange($epg, $date, $date, ['channel.a']);
    expect(collect($range['channel.a'])->pluck('title')->all())->toBe(['A One', 'A Two']);
});

it('still reads a legacy cache that has no offset index by scanning', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create(['is_cached' => true]);
    $date = now()->format('Y-m-d');

    $dir = "epg-cache/{$epg->uuid}/v2";
    Storage::disk('local')->put("{$dir}/metadata.json", json_encode(['cache_version' => 'v2']));
    Storage::disk('local')->put("{$dir}/programmes-{$date}.jsonl", collect([
        ['channel' => 'legacy.a', 'programme' => ['title' => 'Legacy A', 'start' => '2026-01-01T00:00:00Z']],
        ['channel' => 'legacy.b', 'programme' => ['title' => 'Legacy B', 'start' => '2026-01-01T00:00:00Z']],
    ])->map(fn ($r) => json_encode($r))->implode("\n"));

    $programmes = app(EpgCacheService::class)->getCachedProgrammes($epg, $date, ['legacy.b']);

    expect($programmes)->toBe(['legacy.b' => [['title' => 'Legacy B', 'start' => '2026-01-01T00:00:00Z']]]);
});
