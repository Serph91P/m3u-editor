<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SourceGroup;
use App\Models\User;
use Illuminate\Support\Collection;

function runSyncSourceGroupTypeForPrune(
    Playlist $playlist,
    Collection $groups,
    string $type = 'live',
    bool $providerRespondedThisRun = false,
): array {
    $job = new ProcessM3uImport($playlist, force: true, isNew: false);
    $method = new ReflectionMethod($job, 'syncSourceGroupType');

    $selectedKey = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

    return $method->invoke($job, $groups, $type, $selectedKey, [], $playlist, $providerRespondedThisRun);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'import_prefs' => [],
    ]));

    SourceGroup::create([
        'name' => 'Stale Group', 'playlist_id' => $this->playlist->id,
        'source_group_id' => 101, 'type' => 'live',
    ]);
});

describe('SourceGroup stale-prune guard (issue #1530)', function () {
    it('does not prune when this type is empty and no other type responded', function () {
        runSyncSourceGroupTypeForPrune($this->playlist, collect([]), 'live', providerRespondedThisRun: false);

        expect(SourceGroup::where('playlist_id', $this->playlist->id)->where('type', 'live')->count())->toBe(1);
    });

    it('prunes stale rows when this type is empty but another enabled type answered', function () {
        runSyncSourceGroupTypeForPrune($this->playlist, collect([]), 'live', providerRespondedThisRun: true);

        expect(SourceGroup::where('playlist_id', $this->playlist->id)->where('type', 'live')->count())->toBe(0);
    });

    it('still prunes rows no longer in a non-empty feed regardless of the flag', function () {
        runSyncSourceGroupTypeForPrune($this->playlist, collect([
            ['category_id' => 202, 'category_name' => 'Fresh Group'],
        ]), 'live', providerRespondedThisRun: false);

        expect(SourceGroup::where('playlist_id', $this->playlist->id)->where('type', 'live')->pluck('name')->all())
            ->toBe(['Fresh Group']);
    });
});
