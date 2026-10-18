<?php

use App\Filament\Resources\MergedPlaylists\Pages\EditMergedPlaylist;
use App\Filament\Resources\MergedPlaylists\RelationManagers\PlaylistsRelationManager;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\MediaSourceMatch;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\MediaSourceMatchService;
use App\Services\XtreamCategoryService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->provider = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $this->local = Playlist::factory()->for($this->user)->create();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'local',
        'playlist_id' => $this->local->id,
    ]);
    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
});

function matchMovie(Playlist $provider, Channel $providerChannel, Channel $localChannel, MediaServerIntegration $integration): void
{
    MediaSourceMatch::create([
        'playlist_id' => $provider->id,
        'media_server_integration_id' => $integration->id,
        'channel_id' => $providerChannel->id,
        'media_channel_id' => $localChannel->id,
        'match_key' => 'tmdb:'.$localChannel->id,
    ]);
}

it('keeps every local movie by default, even ones the provider already carries', function () {
    $this->merged->playlists()->attach([$this->provider->id, $this->local->id]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => true]);
    $matchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $matchedLocal, $this->integration);

    expect($this->merged->channels()->count())->toBe(3);
});

it('hides local movies a provider in the merge already serves when only_unmatched is on', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => true]);
    $matchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    $unmatchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $matchedLocal, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->sort()->values()->all())
        ->toBe(collect([$providerMovie->id, $unmatchedLocal->id])->sort()->values()->all());
});

it('only counts matches made by playlists attached to the same merge', function () {
    $otherProvider = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $otherProviderMovie = Channel::factory()->create(['playlist_id' => $otherProvider->id, 'is_vod' => true, 'enabled' => true]);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($otherProvider, $otherProviderMovie, $local, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->all())->toBe([$local->id]);
});

it('picks the local movie back up once the match is gone', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => true]);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $local, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->all())->toBe([$providerMovie->id]);

    MediaSourceMatch::query()->delete();

    expect($this->merged->channels()->count())->toBe(2);
});

it('drops a local movie from the merge when the media server removes it', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);

    expect($this->merged->channels()->count())->toBe(1);

    $local->delete();

    expect($this->merged->channels()->count())->toBe(0);
});

it('hides a whole local series once any of its episodes is provided by the merge', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerSeries = Series::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id, 'enabled' => true]);
    $providerEpisode = Episode::factory()->create(['playlist_id' => $this->provider->id, 'series_id' => $providerSeries->id]);

    $matchedSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $matchedSeason = Season::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $matchedSeries->id]);
    $matchedEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $matchedSeries->id, 'season_id' => $matchedSeason->id]);
    $extraEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $matchedSeries->id, 'season_id' => $matchedSeason->id]);

    $unmatchedSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $unmatchedEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $unmatchedSeries->id]);

    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $providerEpisode->id,
        'media_episode_id' => $matchedEpisode->id,
        'match_key' => 'tmdb:1:s1e1',
    ]);

    expect($this->merged->series()->pluck('series.id')->sort()->values()->all())
        ->toBe(collect([$providerSeries->id, $unmatchedSeries->id])->sort()->values()->all())
        ->and($this->merged->seasons()->where('seasons.id', $matchedSeason->id)->exists())->toBeFalse()
        ->and($this->merged->episodes()->pluck('episodes.id')->all())
        ->not->toContain($matchedEpisode->id)
        ->not->toContain($extraEpisode->id)
        ->toContain($unmatchedEpisode->id);
});

it('creates a merged playlist with the provider and each media source flagged only_unmatched', function () {
    $merged = app(MediaSourceMatchService::class)->createMergedWithLocalMedia($this->provider);

    expect($merged)->not->toBeNull()
        ->and($merged->user_id)->toBe($this->user->id)
        ->and($merged->uuid)->not->toBeEmpty();

    $sources = $merged->playlists()->get()->keyBy('id');
    expect($sources->keys()->sort()->values()->all())
        ->toBe(collect([$this->provider->id, $this->local->id])->sort()->values()->all())
        ->and($sources[$this->provider->id]->pivot->only_unmatched)->toBeFalse()
        ->and($sources[$this->local->id]->pivot->only_unmatched)->toBeTrue();
});

it('creates nothing when the owner has no eligible media integration', function () {
    $this->integration->update(['enabled' => false]);

    expect(app(MediaSourceMatchService::class)->createMergedWithLocalMedia($this->provider))->toBeNull()
        ->and(MergedPlaylist::where('name', 'like', '%Local Media%')->exists())->toBeFalse();
});

it('leaves the provider playlist and its auths untouched', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create();
    $auth->assignTo($this->provider);

    app(MediaSourceMatchService::class)->createMergedWithLocalMedia($this->provider);

    expect($this->provider->fresh()->playlistAuths()->pluck('playlist_auths.id')->all())->toBe([$auth->id]);
});

it('offers the action only on provider playlists with prefer-local on and creates the merge when run', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->provider->getRouteKey()])
        ->assertActionVisible('create-merged-with-local-media')
        ->callAction('create-merged-with-local-media')
        ->assertNotified(__('Merged playlist created'));

    expect(MergedPlaylist::where('name', "{$this->provider->name} + Local Media")->exists())->toBeTrue();

    $this->provider->update(['prefer_media_server_sources' => false]);

    Livewire::test(EditPlaylist::class, ['record' => $this->provider->getRouteKey()])
        ->assertActionHidden('create-merged-with-local-media');
});

it('saves the only_unmatched toggle from the merged playlist source editor', function () {
    $this->actingAs($this->user);
    $this->merged->playlists()->attach($this->local->id);

    Livewire::test(PlaylistsRelationManager::class, [
        'ownerRecord' => $this->merged,
        'pageClass' => EditMergedPlaylist::class,
    ])
        ->callAction(TestAction::make('contentTypes')->table($this->local), data: [
            'include_live' => true,
            'include_vod' => true,
            'include_series' => true,
            'only_unmatched' => true,
        ]);

    expect($this->merged->playlists()->find($this->local->id)->pivot->only_unmatched)->toBeTrue();
});

it('keeps a local movie listed when the matched provider movie is disabled', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => false]);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true, 'enabled' => true]);
    matchMovie($this->provider, $providerMovie, $local, $this->integration);

    expect($this->merged->enabled_channels()->pluck('channels.id')->all())->toBe([$local->id]);
});

it('does not list a second copy of a matched movie that shares its tmdb_id', function () {
    $secondLocal = Playlist::factory()->for($this->user)->create();
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach([$this->local->id => ['only_unmatched' => true], $secondLocal->id => ['only_unmatched' => true]]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => true]);
    $matchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true, 'tmdb_id' => '603']);
    $copy = Channel::factory()->create(['playlist_id' => $secondLocal->id, 'is_vod' => true, 'tmdb_id' => '603']);
    $other = Channel::factory()->create(['playlist_id' => $secondLocal->id, 'is_vod' => true, 'tmdb_id' => '604']);
    matchMovie($this->provider, $providerMovie, $matchedLocal, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->sort()->values()->all())
        ->toBe(collect([$providerMovie->id, $other->id])->sort()->values()->all())
        ->not->toContain($copy->id);
});

it('keeps a local series listed when the matched provider series is disabled', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerSeries = Series::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id, 'enabled' => false]);
    $providerEpisode = Episode::factory()->create(['playlist_id' => $this->provider->id, 'series_id' => $providerSeries->id]);
    $localSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $localEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $localSeries->id]);

    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $providerEpisode->id,
        'media_episode_id' => $localEpisode->id,
        'match_key' => 'tmdb:1:s1e1',
    ]);

    expect($this->merged->series()->pluck('series.id')->all())->toContain($localSeries->id)
        ->and($this->merged->episodes()->pluck('episodes.id')->all())->toContain($localEpisode->id);
});

it('does not list a second copy of a matched series that shares its tmdb_id', function () {
    $secondLocal = Playlist::factory()->for($this->user)->create();
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach([$this->local->id => ['only_unmatched' => true], $secondLocal->id => ['only_unmatched' => true]]);

    $providerSeries = Series::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id, 'enabled' => true]);
    $providerEpisode = Episode::factory()->create(['playlist_id' => $this->provider->id, 'series_id' => $providerSeries->id]);
    $matchedSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id, 'tmdb_id' => '1399']);
    $matchedEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $matchedSeries->id]);
    $copySeries = Series::factory()->create(['playlist_id' => $secondLocal->id, 'user_id' => $this->user->id, 'tmdb_id' => '1399']);

    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $providerEpisode->id,
        'media_episode_id' => $matchedEpisode->id,
        'match_key' => 'tmdb:1399:s1e1',
    ]);

    expect($this->merged->series()->pluck('series.id')->all())->toBe([$providerSeries->id])
        ->not->toContain($copySeries->id);
});

it('keeps a local movie listed when the matching provider does not contribute VOD to the merge', function () {
    $this->merged->playlists()->attach($this->provider->id, ['include_vod' => false]);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => true]);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $local, $this->integration);

    expect($this->merged->channels()->pluck('channels.id')->all())->toBe([$local->id]);
});

it('keeps a local series listed when the matching provider does not contribute series to the merge', function () {
    $this->merged->playlists()->attach($this->provider->id, ['include_series' => false]);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerSeries = Series::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id, 'enabled' => true]);
    $providerEpisode = Episode::factory()->create(['playlist_id' => $this->provider->id, 'series_id' => $providerSeries->id]);
    $localSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $localEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $localSeries->id]);

    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $providerEpisode->id,
        'media_episode_id' => $localEpisode->id,
        'match_key' => 'tmdb:1:s1e1',
    ]);

    expect($this->merged->series()->pluck('series.id')->all())->toBe([$localSeries->id])
        ->and($this->merged->episodes()->pluck('episodes.id')->all())->toBe([$localEpisode->id]);
});

it('never hides the provider title when only_unmatched is turned on for the provider row', function () {
    $this->merged->playlists()->attach($this->provider->id, ['only_unmatched' => true]);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => true, 'tmdb_id' => '603']);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true, 'tmdb_id' => '603']);
    matchMovie($this->provider, $providerMovie, $local, $this->integration);

    $providerSeries = Series::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id, 'enabled' => true, 'tmdb_id' => '1399']);
    $providerEpisode = Episode::factory()->create(['playlist_id' => $this->provider->id, 'series_id' => $providerSeries->id]);
    $localSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id, 'tmdb_id' => '1399']);
    $localEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $localSeries->id]);

    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $providerEpisode->id,
        'media_episode_id' => $localEpisode->id,
        'match_key' => 'tmdb:1399:s1e1',
    ]);

    expect($this->merged->channels()->pluck('channels.id')->all())->toBe([$providerMovie->id])
        ->and($this->merged->series()->pluck('series.id')->all())->toBe([$providerSeries->id])
        ->and($this->merged->episodes()->pluck('episodes.id')->all())->toBe([$providerEpisode->id]);
});

it('applies only_unmatched to aliases of the merged playlist', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'is_vod' => true, 'enabled' => true]);
    $local = Channel::factory()->create(['playlist_id' => $this->local->id, 'is_vod' => true]);
    matchMovie($this->provider, $providerMovie, $local, $this->integration);

    $providerSeries = Series::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id, 'enabled' => true]);
    $providerEpisode = Episode::factory()->create(['playlist_id' => $this->provider->id, 'series_id' => $providerSeries->id]);
    $localSeries = Series::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $localEpisode = Episode::factory()->create(['playlist_id' => $this->local->id, 'series_id' => $localSeries->id]);

    MediaSourceMatch::create([
        'playlist_id' => $this->provider->id,
        'media_server_integration_id' => $this->integration->id,
        'episode_id' => $providerEpisode->id,
        'media_episode_id' => $localEpisode->id,
        'match_key' => 'tmdb:1:s1e1',
    ]);

    $alias = PlaylistAlias::create([
        'merged_playlist_id' => $this->merged->id,
        'user_id' => $this->user->id,
        'name' => 'Merged Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => [['url' => 'http://provider.example.com', 'username' => 'u', 'password' => 'p']],
    ]);

    expect($alias->channels()->pluck('channels.id')->all())->toBe([$providerMovie->id])
        ->and($alias->series()->pluck('series.id')->all())->toBe([$providerSeries->id]);
});

it('opens the existing merge instead of creating a duplicate when the action runs again', function () {
    $this->actingAs($this->user);

    Livewire::test(EditPlaylist::class, ['record' => $this->provider->getRouteKey()])
        ->callAction('create-merged-with-local-media')
        ->assertNotified(__('Merged playlist created'));

    Livewire::test(EditPlaylist::class, ['record' => $this->provider->getRouteKey()])
        ->callAction('create-merged-with-local-media')
        ->assertNotified(__('Merged playlist already exists'));

    expect(MergedPlaylist::where('user_id', $this->user->id)->where('name', 'like', '%Local Media%')->count())->toBe(1);
});

it('still finds the existing merge after the owner adds another media integration', function () {
    $service = app(MediaSourceMatchService::class);
    $merged = $service->createMergedWithLocalMedia($this->provider);

    $secondLocal = Playlist::factory()->for($this->user)->create();
    MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'local',
        'playlist_id' => $secondLocal->id,
    ]);

    expect($service->findMergedWithLocalMedia($this->provider)?->id)->toBe($merged->id);
});

it('drops a VOD category whose movies are all hidden by only_unmatched', function () {
    $this->merged->playlists()->attach($this->provider->id);
    $this->merged->playlists()->attach($this->local->id, ['only_unmatched' => true]);

    $providerGroup = Group::factory()->create(['playlist_id' => $this->provider->id, 'user_id' => $this->user->id]);
    $hiddenGroup = Group::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);
    $keptGroup = Group::factory()->create(['playlist_id' => $this->local->id, 'user_id' => $this->user->id]);

    $providerMovie = Channel::factory()->create(['playlist_id' => $this->provider->id, 'group_id' => $providerGroup->id, 'is_vod' => true, 'enabled' => true]);
    $matchedLocal = Channel::factory()->create(['playlist_id' => $this->local->id, 'group_id' => $hiddenGroup->id, 'is_vod' => true, 'enabled' => true]);
    Channel::factory()->create(['playlist_id' => $this->local->id, 'group_id' => $keptGroup->id, 'is_vod' => true, 'enabled' => true]);
    matchMovie($this->provider, $providerMovie, $matchedLocal, $this->integration);

    $alias = PlaylistAlias::create([
        'merged_playlist_id' => $this->merged->id,
        'user_id' => $this->user->id,
        'name' => 'Merged Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => [['url' => 'http://provider.example.com', 'username' => 'u', 'password' => 'p']],
    ]);

    $expected = collect([$providerGroup->id, $keptGroup->id])->map(fn ($id) => (string) $id)->sort()->values()->all();

    foreach ([$this->merged, $alias] as $playlist) {
        $categoryIds = collect(XtreamCategoryService::groupCategories($playlist, true))->pluck('category_id')->sort()->values()->all();

        expect($categoryIds)->toBe($expected);
    }
});
