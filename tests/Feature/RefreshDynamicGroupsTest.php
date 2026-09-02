<?php

use App\Jobs\SyncDynamicGroups;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
});

it('dispatches SyncDynamicGroups only for playlists with an enabled rule', function () {
    $withEnabledRule = Playlist::factory()->for($this->user)->createQuietly([
        'dynamic_groups_config' => [
            ['type' => 'vod', 'source' => 'trending', 'name' => 'Trending', 'enabled' => true],
        ],
    ]);
    $onlyDisabledRules = Playlist::factory()->for($this->user)->createQuietly([
        'dynamic_groups_config' => [
            ['type' => 'vod', 'source' => 'popular', 'name' => 'Popular', 'enabled' => false],
        ],
    ]);
    $noConfig = Playlist::factory()->for($this->user)->createQuietly([
        'dynamic_groups_config' => null,
    ]);

    $this->artisan('app:refresh-dynamic-groups')
        ->expectsOutputToContain('Dispatched SyncDynamicGroups for 1 playlist(s); skipped 1')
        ->assertSuccessful();

    Bus::assertDispatched(
        SyncDynamicGroups::class,
        fn (SyncDynamicGroups $job): bool => $job->playlistId === $withEnabledRule->id,
    );
    Bus::assertDispatchedTimes(SyncDynamicGroups::class, 1);

    // The whereNotNull('dynamic_groups_config') filter never loads $noConfig,
    // so it is not even counted as skipped.
    expect($noConfig->fresh()->dynamic_groups_config)->toBeNull()
        ->and($onlyDisabledRules->id)->not->toBe($withEnabledRule->id);
});

it('recognises an enabled rule via DynamicGroup::configHasEnabledRule', function () {
    expect(DynamicGroup::configHasEnabledRule(null))->toBeFalse()
        ->and(DynamicGroup::configHasEnabledRule([]))->toBeFalse()
        ->and(DynamicGroup::configHasEnabledRule([['enabled' => false]]))->toBeFalse()
        ->and(DynamicGroup::configHasEnabledRule([['name' => 'x']]))->toBeFalse()
        ->and(DynamicGroup::configHasEnabledRule([['enabled' => false], ['enabled' => true]]))->toBeTrue();
});
