<?php

/**
 * Reopening a saved bouquet must show the groups that were selected: the edit
 * form has to translate the persisted group_selections (provider-stable names,
 * or {playlist_id, name} pairs for a merged target) back into the picker ids
 * the ModalTableSelect renders as badges and pre-selects in its modal table.
 */

use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\SourceGroup;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The badge labels the visible picker for $key renders in the mounted edit form.
 *
 * @return array<int|string, string>
 */
function bouquetEditPickerLabels(Testable $component, string $key): array
{
    $page = $component->instance();
    $schema = $page->{$page->getMountedActionSchemaName()};

    return $schema->getComponent($key, withHidden: false)->getOptionLabels();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('shows the saved groups of a standard-target bouquet when reopened for editing', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly();
    $sports = SourceGroup::create(['name' => 'Hydrate Sports', 'playlist_id' => $playlist->id, 'type' => 'live']);
    $news = SourceGroup::create(['name' => 'Hydrate News', 'playlist_id' => $playlist->id, 'type' => 'live']);
    SourceGroup::create(['name' => 'Hydrate Unselected', 'playlist_id' => $playlist->id, 'type' => 'live']);

    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_selections' => ['selected_groups' => ['Hydrate Sports', 'Hydrate News']],
    ]);

    $component = Livewire::test(ListBouquets::class)
        ->mountTableAction('edit', $bouquet)
        ->assertTableActionDataSet(function (array $state) use ($sports, $news): array {
            expect($state['group_selections']['selected_groups'] ?? null)
                ->toEqualCanonicalizing([$sports->id, $news->id]);

            return [];
        });

    $labels = bouquetEditPickerLabels($component, 'group_selections.selected_groups');
    expect(array_keys($labels))->toEqualCanonicalizing([$sports->id, $news->id])
        ->and(array_values($labels))->toEqualCanonicalizing(['Hydrate Sports', 'Hydrate News']);
});

it('shows the saved groups of a custom-playlist-target bouquet when reopened for editing', function () {
    $custom = CustomPlaylist::factory()->for($this->user)->create();

    $bouquet = Bouquet::factory()->forCustomPlaylist($custom)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_groups' => ['Hydrate Custom Sports']],
    ]);

    $component = Livewire::test(ListBouquets::class)
        ->mountTableAction('edit', $bouquet)
        ->assertTableActionDataSet(['group_selections.selected_groups' => ['Hydrate Custom Sports']]);

    expect(bouquetEditPickerLabels($component, 'group_selections.selected_groups'))
        ->toBe(['Hydrate Custom Sports' => 'Hydrate Custom Sports']);
});

it('shows the saved groups of a merged-target bouquet when reopened for editing', function () {
    $sourceA = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Provider A']);
    $sourceB = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Provider B']);
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach([$sourceA->id, $sourceB->id]);

    $aSports = SourceGroup::create(['playlist_id' => $sourceA->id, 'name' => 'Hydrate Sports', 'type' => 'live']);
    $bSports = SourceGroup::create(['playlist_id' => $sourceB->id, 'name' => 'Hydrate Sports', 'type' => 'live']);
    SourceGroup::create(['playlist_id' => $sourceA->id, 'name' => 'Hydrate News', 'type' => 'live']);

    $bouquet = Bouquet::factory()->forMergedPlaylist($merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_groups' => [
            ['playlist_id' => $sourceA->id, 'name' => 'Hydrate Sports'],
            ['playlist_id' => $sourceB->id, 'name' => 'Hydrate Sports'],
        ]],
    ]);

    $component = Livewire::test(ListBouquets::class)
        ->mountTableAction('edit', $bouquet)
        ->assertTableActionDataSet(function (array $state) use ($aSports, $bSports): array {
            expect($state['group_selections']['selected_groups'] ?? null)
                ->toEqualCanonicalizing([$aSports->id, $bSports->id]);

            return [];
        });

    $labels = bouquetEditPickerLabels($component, 'group_selections.selected_groups');
    expect(array_keys($labels))->toEqualCanonicalizing([$aSports->id, $bSports->id])
        ->and(array_values($labels))->toEqualCanonicalizing(['Hydrate Sports (Provider A)', 'Hydrate Sports (Provider B)']);
});

it('keeps a merged-target bouquet\'s selections when it is saved again without touching the pickers', function () {
    $sourceA = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Provider A']);
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach([$sourceA->id]);
    SourceGroup::create(['playlist_id' => $sourceA->id, 'name' => 'Hydrate Sports', 'type' => 'live']);

    $bouquet = Bouquet::factory()->forMergedPlaylist($merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_groups' => [['playlist_id' => $sourceA->id, 'name' => 'Hydrate Sports']]],
    ]);

    Livewire::test(ListBouquets::class)
        ->callTableAction('edit', $bouquet, data: ['name' => 'Renamed bouquet'])
        ->assertHasNoTableActionErrors();

    expect($bouquet->refresh()->getSelectedLiveGroupSelections())
        ->toBe([['playlist_id' => $sourceA->id, 'name' => 'Hydrate Sports']]);
});
