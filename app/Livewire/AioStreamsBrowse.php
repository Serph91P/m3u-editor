<?php

namespace App\Livewire;

use App\Models\CustomPlaylist;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\ViewerWatchProgress;
use App\Services\AIOStreamsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Browse/watch UI for a single AIOStreams (Stremio addon) integration.
 * Reused unchanged by the admin "Browse Catalog" page and the guest panel,
 * mirroring how ArrSearch is shared between RequestContent and GuestRequestContent.
 */
class AioStreamsBrowse extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public int $integrationId;

    public bool $guestMode = false;

    public ?int $playlistAuthId = null;

    public string $searchTerm = '';

    public string $typeFilter = 'all';

    public bool $isSearching = false;

    /** @var array<int, array<string, mixed>> */
    public array $searchResults = [];

    public bool $showDetail = false;

    /** @var array<string, mixed>|null Raw Stremio meta object. */
    public ?array $detailResult = null;

    public ?string $detailType = null;

    public ?string $detailId = null;

    /**
     * Available season numbers for the current series — lightweight, just for
     * rendering the season tab buttons. The actual per-episode data is loaded
     * lazily per season (see loadSeasonEpisodes()), not all at once.
     *
     * @var array<int, int>
     */
    public array $detailSeasons = [];

    /**
     * Episodes for ONLY the currently selected season — intentionally never holds
     * more than one season's worth of data, to keep Livewire's serialized state
     * (and the rendered DOM) bounded regardless of how many seasons/episodes a
     * series has.
     *
     * @var array<int, array<int, array<string, mixed>>> keyed by season number
     */
    public array $detailEpisodesBySeason = [];

    public ?int $detailSelectedSeason = null;

    /** @var array<int, array<string, mixed>> */
    public array $streamChoices = [];

    /** @var array<string, mixed>|null */
    public ?array $pendingWatchContext = null;

    public function mount(int $integrationId, bool $guestMode = false, ?int $playlistAuthId = null): void
    {
        $this->integrationId = $integrationId;
        $this->guestMode = $guestMode;
        $this->playlistAuthId = $playlistAuthId;
    }

    public function getIntegrationProperty(): ?MediaServerIntegration
    {
        return MediaServerIntegration::query()
            ->where('id', $this->integrationId)
            ->where('type', 'aiostreams')
            ->first();
    }

    /**
     * Catalogs for the current type filter, rendered as one deferred
     * AioStreamsCatalogRow per catalog. Cheap — reads directly off the
     * integration's cached manifest, no HTTP involved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCatalogsProperty(): array
    {
        return $this->enabledCatalogs();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function enabledCatalogs(): array
    {
        $integration = $this->integration;
        if (! $integration) {
            return [];
        }

        $catalogs = collect($integration->aiostreams_catalogs ?? []);

        if (! $integration->aiostreams_enable_all_catalogs) {
            $selected = collect($integration->aiostreams_selected_catalog_ids ?? [])->flip();
            $catalogs = $catalogs->filter(fn (array $c) => $selected->has($c['id'].'_'.$c['type']));
        }

        if ($this->typeFilter !== 'all') {
            $catalogs = $catalogs->where('type', $this->typeFilter);
        }

        return $catalogs->values()->all();
    }

    public function updatedTypeFilter(): void
    {
        if (mb_strlen(trim($this->searchTerm)) >= 2) {
            $this->search();
        }
    }

    /**
     * Catalog cards (in both the search grid and each AioStreamsCatalogRow child)
     * dispatch this instead of calling openDetail() directly, since a card rendered
     * inside a child row component can't call a parent component method directly.
     */
    #[On('openAioDetail')]
    public function handleOpenAioDetail(string $type, string $id): void
    {
        $this->openDetail($type, $id);
    }

    public function search(): void
    {
        $term = trim($this->searchTerm);
        if (mb_strlen($term) < 2) {
            $this->searchResults = [];

            return;
        }

        $integration = $this->integration;
        if (! $integration) {
            return;
        }

        $this->isSearching = true;

        $service = AIOStreamsService::make($integration);
        $results = [];

        foreach ($this->enabledCatalogs() as $catalog) {
            if (empty($catalog['searchable'])) {
                continue;
            }

            try {
                $data = $service->fetchCatalog($catalog['type'], $catalog['id'], extra: ['search' => $term]);
                foreach ($data['metas'] ?? [] as $item) {
                    $results[] = $item;
                }
            } catch (\Exception) {
                continue;
            }
        }

        $this->searchResults = collect($results)->unique('id')->values()->all();
        $this->isSearching = false;
    }

    public function clearSearch(): void
    {
        $this->searchTerm = '';
        $this->searchResults = [];
    }

    public function openDetail(string $type, string $id): void
    {
        if (! $this->loadDetail($type, $id)) {
            return;
        }

        $this->showDetail = true;
        $this->mountAction('showDetail');
    }

    /**
     * Resume a Continue Watching item directly — skips the detail slide-over and
     * jumps straight to fetching/playing the stream (the video player's own resume
     * prompt, driven by the saved position, picks up from where it left off). The
     * season/episode is already known from the progress row, so the (often large)
     * per-episode video list isn't needed here — skip building/storing it.
     */
    public function resumeWatch(int $progressId): void
    {
        $viewer = $this->resolveViewer();
        $progress = $viewer
            ? ViewerWatchProgress::query()
                ->aiostreams()
                ->where('playlist_viewer_id', $viewer->id)
                ->where('aio_integration_id', $this->integrationId)
                ->find($progressId)
            : null;

        if (! $progress) {
            return;
        }

        $type = $progress->season_number ? 'series' : 'movie';

        if (! $this->loadDetail($type, $progress->aio_item_id, includeEpisodes: false)) {
            return;
        }

        $this->playStream($progress->season_number, $progress->episode_number);

        // playStream() derives episode_title/thumbnail_url/plot from the per-episode
        // video list, which is skipped above — fall back to what was already saved
        // on the progress row itself.
        if ($this->pendingWatchContext) {
            $this->pendingWatchContext['episode_title'] ??= $progress->episode_title;
            $this->pendingWatchContext['thumbnail_url'] ??= $progress->thumbnail_url;
            $this->pendingWatchContext['plot'] ??= $progress->plot;
        }
    }

    /**
     * Fetch Stremio meta for an item and populate the detail-related component state.
     * Shared by openDetail() (which additionally opens the slide-over) and
     * resumeWatch() (which plays immediately instead).
     */
    private function loadDetail(string $type, string $id, bool $includeEpisodes = true): bool
    {
        $integration = $this->integration;
        if (! $integration) {
            return false;
        }

        $response = AIOStreamsService::make($integration)->fetchMeta($type, $id);
        $meta = $response['meta'] ?? null;

        if (! $meta) {
            Notification::make()->danger()->title(__('Failed to load details'))->send();

            return false;
        }

        $this->detailType = $type;
        $this->detailId = $id;
        $this->detailSeasons = [];
        $this->detailEpisodesBySeason = [];
        $this->detailSelectedSeason = null;
        $this->streamChoices = [];
        $this->pendingWatchContext = null;

        if ($type === 'series' && ! empty($meta['videos']) && $includeEpisodes) {
            Cache::put($this->episodeCacheKey($id), $meta['videos'], now()->addMinutes(10));

            $this->detailSeasons = collect($meta['videos'])
                ->map(fn (array $video) => (int) ($video['season'] ?? 0))
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (! empty($this->detailSeasons)) {
                $this->loadSeasonEpisodes($this->detailSeasons[0]);
            }
        }

        // The full per-episode video list is never kept in $detailResult — it's
        // sourced from the cache above (or skipped entirely for resumeWatch) and
        // would otherwise bloat every subsequent Livewire request for this component.
        unset($meta['videos']);
        $this->detailResult = $meta;

        return true;
    }

    private function episodeCacheKey(string $id): string
    {
        return "aiostreams:videos:{$this->integrationId}:{$id}";
    }

    /**
     * Populate $detailEpisodesBySeason with ONLY the given season's episodes,
     * replacing whatever season was previously loaded — this is what keeps the
     * component's state (and the rendered DOM) bounded regardless of series length.
     * Reads from the per-item cache populated in loadDetail(); if it's expired,
     * transparently re-fetches the meta to rebuild it.
     */
    private function loadSeasonEpisodes(int $season): void
    {
        if (! $this->detailType || ! $this->detailId) {
            return;
        }

        $videos = Cache::get($this->episodeCacheKey($this->detailId));

        if ($videos === null) {
            $integration = $this->integration;
            if (! $integration) {
                return;
            }

            $response = AIOStreamsService::make($integration)->fetchMeta($this->detailType, $this->detailId);
            $videos = $response['meta']['videos'] ?? [];
            Cache::put($this->episodeCacheKey($this->detailId), $videos, now()->addMinutes(10));
        }

        $episodes = collect($videos)
            ->filter(fn (array $video) => (int) ($video['season'] ?? 0) === $season)
            ->map(fn (array $video) => $this->normalizeEpisodeVideo($video))
            ->values()
            ->all();

        $this->detailEpisodesBySeason = [$season => $episodes];
        $this->detailSelectedSeason = $season;
    }

    /**
     * Normalize a Stremio "video" (episode) object into a consistent shape — addons
     * vary in whether they use title/name, overview/description, thumbnail/poster.
     *
     * @param  array<string, mixed>  $video
     * @return array<string, mixed>
     */
    private function normalizeEpisodeVideo(array $video): array
    {
        return [
            'episode' => (int) ($video['episode'] ?? 0),
            'title' => $video['title'] ?? $video['name'] ?? null,
            'overview' => $video['overview'] ?? $video['description'] ?? null,
            'thumbnail' => $video['thumbnail'] ?? $video['poster'] ?? null,
            'released' => $video['released'] ?? $video['firstAired'] ?? null,
        ];
    }

    public function showDetailAction(): Action
    {
        return Action::make('showDetail')
            ->slideOver()
            ->modalHeading(false)
            ->modalContent(fn () => view('livewire.partials.aiostreams-detail', [
                'detailResult' => $this->detailResult,
                'detailType' => $this->detailType,
                'detailSeasons' => $this->detailSeasons,
                'detailEpisodesBySeason' => $this->detailEpisodesBySeason,
                'detailSelectedSeason' => $this->detailSelectedSeason,
                'streamChoices' => $this->streamChoices,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }

    #[Renderless]
    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailResult = null;
        $this->detailType = null;
        $this->detailId = null;
        $this->detailSeasons = [];
        $this->detailEpisodesBySeason = [];
        $this->detailSelectedSeason = null;
        $this->streamChoices = [];
        $this->pendingWatchContext = null;
        $this->unmountAction();
    }

    public function selectSeason(int $season): void
    {
        if ($season === $this->detailSelectedSeason) {
            return;
        }

        $this->loadSeasonEpisodes($season);
    }

    public function playStream(?int $season = null, ?int $episode = null): void
    {
        $integration = $this->integration;
        if (! $integration || ! $this->detailResult || ! $this->detailType || ! $this->detailId) {
            return;
        }

        $streamLookupId = $this->detailId;
        $episodeVideo = null;

        if ($this->detailType === 'series' && $season !== null && $episode !== null) {
            $streamLookupId = "{$this->detailId}:{$season}:{$episode}";
            $episodeVideo = collect($this->detailEpisodesBySeason[$season] ?? [])
                ->first(fn (array $v) => (int) ($v['episode'] ?? 0) === $episode);
        }

        try {
            $data = AIOStreamsService::make($integration)->fetchStreams($this->detailType, $streamLookupId);
        } catch (\Exception $e) {
            Notification::make()->danger()->title(__('Failed to load streams'))->body($e->getMessage())->send();

            return;
        }

        $streams = $data['streams'] ?? [];
        if (empty($streams)) {
            Notification::make()->warning()->title(__('No playable streams found'))->send();

            return;
        }

        // Always show the source picker rather than auto-playing a lone result — a
        // single "stream" is frequently a trailer/error placeholder from the addon
        // rather than a real playable source, so let the user confirm the choice.
        $this->streamChoices = $streams;
        $this->pendingWatchContext = $this->buildWatchContext($season, $episode, $episodeVideo);

        $this->showDetail = true;
        $this->mountAction('showDetail');
    }

    public function playChosenStream(int $index): void
    {
        if (! isset($this->streamChoices[$index])) {
            return;
        }

        $this->dispatchPlay($this->streamChoices[$index], $this->pendingWatchContext ?? []);
        $this->streamChoices = [];
        $this->pendingWatchContext = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWatchContext(?int $season, ?int $episode, ?array $episodeVideo): array
    {
        $meta = $this->detailResult ?? [];

        return [
            'aio_item_id' => $this->detailId,
            'aio_integration_id' => $this->integrationId,
            'title' => $meta['name'] ?? null,
            'episode_title' => $episodeVideo['title'] ?? null,
            'season_number' => $season,
            'episode_number' => $episode,
            'thumbnail_url' => $episodeVideo['thumbnail'] ?? $meta['poster'] ?? null,
            'backdrop_url' => $meta['background'] ?? null,
            'rating' => $meta['imdbRating'] ?? null,
            'year' => $meta['releaseInfo'] ?? $meta['year'] ?? null,
            'plot' => $episodeVideo['overview'] ?? $meta['description'] ?? null,
        ];
    }

    private function dispatchPlay(array $stream, array $context): void
    {
        $url = $stream['url'] ?? null;
        if (! $url) {
            Notification::make()->danger()->title(__('This source has no playable URL'))->send();

            return;
        }

        $format = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'mp4';

        $title = $context['title'] ?? __('Unknown');
        $displayTitle = $title;

        if (! empty($context['episode_number'])) {
            $displayTitle .= ' - S'.str_pad((string) $context['season_number'], 2, '0', STR_PAD_LEFT)
                .'E'.str_pad((string) $context['episode_number'], 2, '0', STR_PAD_LEFT);

            if (! empty($context['episode_title'])) {
                $displayTitle .= ' - '.$context['episode_title'];
            }
        }

        $playerId = 'aiostreams-'.$context['aio_item_id'];
        if (! empty($context['episode_number'])) {
            $playerId .= '-'.$context['season_number'].'x'.$context['episode_number'];
        }

        $this->dispatch('openFloatingStream', array_merge($context, [
            'id' => $playerId,
            'content_type' => 'aiostreams',
            'title' => $title,
            'display_title' => $displayTitle,
            'logo' => $context['thumbnail_url'] ?? null,
            'url' => $url,
            'format' => $format,
            'type' => 'aiostreams',
        ]));

        $this->closeDetail();
    }

    /**
     * @return Collection<int, ViewerWatchProgress>
     */
    public function getContinueWatchingProperty(): Collection
    {
        $viewer = $this->resolveViewer();
        if (! $viewer) {
            return new Collection;
        }

        return ViewerWatchProgress::query()
            ->aiostreams()
            ->where('playlist_viewer_id', $viewer->id)
            ->where('aio_integration_id', $this->integrationId)
            ->where('completed', false)
            ->where('position_seconds', '>=', 30)
            ->orderByDesc('last_watched_at')
            ->limit(12)
            ->get();
    }

    private function resolveViewer(): ?PlaylistViewer
    {
        $integration = $this->integration;
        if (! $integration) {
            return null;
        }

        $playlist = null;
        foreach ([Playlist::class, CustomPlaylist::class, MergedPlaylist::class] as $model) {
            $playlist = $model::where('user_id', $integration->user_id)
                ->where('aiostreams_integration_id', $integration->id)
                ->first();
            if ($playlist) {
                break;
            }
        }
        $playlist ??= Playlist::where('user_id', $integration->user_id)->first();

        if (! $playlist) {
            return null;
        }

        if ($this->guestMode) {
            if (! $this->playlistAuthId) {
                return null;
            }

            $auth = PlaylistAuth::find($this->playlistAuthId);
            if (! $auth) {
                return null;
            }

            return PlaylistViewer::where('playlist_auth_id', $auth->id)
                ->where('viewerable_type', $playlist->getMorphClass())
                ->where('viewerable_id', $playlist->id)
                ->first();
        }

        if (! auth()->check()) {
            return null;
        }

        return PlaylistViewer::where('viewerable_type', $playlist->getMorphClass())
            ->where('viewerable_id', $playlist->id)
            ->where('is_admin', true)
            ->first();
    }

    public function render()
    {
        return view('livewire.aio-streams-browse');
    }
}
