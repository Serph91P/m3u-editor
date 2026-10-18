<?php

use App\Livewire\EpgViewer;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * #1623 - after editing a channel in the EPG viewer, the refreshed row must keep
 * the channel's EPG shift (tvg_shift) applied to its programmes.
 */
uses(RefreshDatabase::class);

it('applies the channel tvg_shift when the viewer refreshes a channel\'s programmes', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create(['is_cached' => true]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create(['channel_id' => 'shift.channel']);

    $start = now()->startOfDay()->addHours(10);
    seedEpgProgrammeCache($epg, [
        ['shift.channel', [
            'title' => 'Morning Show',
            'start' => $start->format('Y-m-d H:i:s'),
            'stop' => $start->copy()->addHour()->format('Y-m-d H:i:s'),
        ]],
    ]);

    $fetch = new ReflectionMethod(EpgViewer::class, 'fetchProgrammeData');
    $viewer = new EpgViewer;

    $unshifted = $fetch->invoke($viewer, $epgChannel, 1);
    $shifted = $fetch->invoke($viewer, $epgChannel, 1, 2);

    expect(Carbon::parse($unshifted[0]['start'])->equalTo($start))->toBeTrue()
        ->and(Carbon::parse($shifted[0]['start'])->equalTo($start->copy()->addHours(2)))->toBeTrue();
});
