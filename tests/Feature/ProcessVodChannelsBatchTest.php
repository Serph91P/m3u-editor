<?php

use App\Jobs\ProcessVodChannels;
use App\Jobs\ProcessVodChannelsChunk;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\XtreamService;
use Illuminate\Support\Facades\Bus;

it('dispatches VOD chunks as an allow-failures batch instead of a chain', function () {
    $playlist = Playlist::factory()->for(User::factory())->create();

    collect(['1', '2', '3'])->each(fn (string $id) => Channel::factory()->for($playlist)->for($playlist->user)->create([
        'is_vod' => true,
        'enabled' => true,
        'source_id' => $id,
        'last_metadata_fetch' => null,
    ]));

    Bus::fake();

    (new ProcessVodChannels(playlist: $playlist))->handle(app(XtreamService::class));

    Bus::assertBatched(function ($batch) {
        return count($batch->jobs) === 1
            && $batch->jobs[0] instanceof ProcessVodChannelsChunk
            && $batch->allowsFailures();
    });
});
