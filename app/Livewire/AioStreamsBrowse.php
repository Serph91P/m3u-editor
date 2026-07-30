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

    public bool $streamsLoading = false;

    public bool $streamsFailed = false;

    /**
     * Season/episode currently being resolved into streams — set at the top of
     * playStream() regardless of entry point, so retryLoadStreams() always knows
     * what to re-fetch.
     */
    public ?int $resumeSeason = null;

    public ?int $resumeEpisode = null;

    /**
     * Only populated by resumeWatch(), since the per-episode video list (which
     * would normally supply this) is intentionally never fetched for a resume —
     * see resumeWatch()'s docblock.
     */
    public ?string $resumeEpisodeTitle = null;

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
     * Resume a Continue Watching item. Opens the source-picker modal INSTANTLY
     * using only the fields already saved on the progress row itself — zero
     * network calls — since fetching full Stremio meta (and, for a series, its
     * whole episode list) before the modal could open was the cause of a large
     * click-to-open delay. The actual stream lookup is kicked off afterwards by
     * loadResumeStreams(), triggered client-side via wire:init once the modal
     * is already visible.
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

        $this->detailType = $progress->season_number ? 'series' : 'movie';
        $this->detailId = $progress->aio_item_id;
        $this->detailSeasons = [];
        $this->detailEpisodesBySeason = [];
        $this->detailSelectedSeason = null;
        $this->streamChoices = [];
        $this->pendingWatchContext = null;

        $this->detailResult = [
            'name' => $progress->title,
            'poster' => $progress->thumbnail_url,
            'background' => $progress->backdrop_url,
            'imdbRating' => $progress->rating,
            'releaseInfo' => $progress->year,
            'description' => $progress->plot,
        ];

        $this->resumeSeason = $progress->season_number;
        $this->resumeEpisode = $progress->episode_number;
        $this->resumeEpisodeTitle = $progress->episode_title;
        $this->streamsLoading = true;
        $this->streamsFailed = false;

        $this->showDetail = true;
        $this->mountAction('showDetail');
    }

    /**
     * Fires once, client-side, right after the resume modal opens (via wire:init
     * in the Blade partial) — this is what actually looks up playable streams,
     * deferred so it never blocks the modal from opening.
     *
     * Deliberately #[Renderless]: this call has no originating click for
     * Filament's focus-trap to anchor to, and a normal (rendered) Livewire
     * request re-diffs this component's ENTIRE DOM — including the catalog grid
     * sitting behind the modal — which was enough on its own (independent of the
     * earlier double-mountAction() bug) to tear down and reinitialize every lazy
     * AioStreamsCatalogRow's x-intersect binding, forcing them all to load at
     * once and jump-scrolling the page to wherever that landed. Renderless skips
     * the re-render/morph entirely; the fetched streams are instead pushed to
     * the client via a dispatched event and rendered by Alpine, isolated from
     * the rest of the page. See aiostreams-detail.blade.php.
     */
    #[Renderless]
    public function loadResumeStreams(): void
    {
        $this->playStream($this->resumeSeason, $this->resumeEpisode);

        // playStream() derives episode_title from the per-episode video list,
        // which resumeWatch() intentionally never fetches — fall back to what
        // was already saved on the progress row.
        if ($this->pendingWatchContext) {
            $this->pendingWatchContext['episode_title'] ??= $this->resumeEpisodeTitle;
        }

        $this->dispatch('aio-streams-loaded', failed: $this->streamsFailed, streams: $this->streamChoices);
    }

    #[Renderless]
    public function retryLoadStreams(): void
    {
        $this->loadResumeStreams();
    }

    /**
     * Fetch Stremio meta for an item and populate the detail-related component state.
     * Shared by openDetail() (which additionally opens the slide-over) and
     * resumeWatch() (which plays immediately instead).
     */
    private function loadDetail(string $type, string $id): bool
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
        $this->resumeSeason = null;
        $this->resumeEpisode = null;
        $this->resumeEpisodeTitle = null;
        $this->streamsLoading = false;
        $this->streamsFailed = false;

        if ($type === 'series' && ! empty($meta['videos'])) {
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
            // Filament's focus trap auto-focuses the modal's first focusable element
            // when it opens, and the browser's default focus() scrolls that element
            // into view. The modal is teleported to the end of <body> (after every
            // catalog row), so if the trap activates before the teleported node's
            // fixed positioning has taken effect, that scroll lands on the still
            // in-flow node at the bottom of the page — force-loading every lazy
            // catalog row along the way. Disabling autofocus removes the trigger.
            ->modalAutofocus(false)
            ->modalContent(fn () => view('livewire.partials.aiostreams-detail', [
                'detailResult' => $this->detailResult,
                'detailType' => $this->detailType,
                'detailSeasons' => $this->detailSeasons,
                'detailEpisodesBySeason' => $this->detailEpisodesBySeason,
                'detailSelectedSeason' => $this->detailSelectedSeason,
                'streamChoices' => $this->streamChoices,
                'streamsLoading' => $this->streamsLoading,
                'streamsFailed' => $this->streamsFailed,
                'resumeSeason' => $this->resumeSeason,
                'resumeEpisode' => $this->resumeEpisode,
                'resumeEpisodeTitle' => $this->resumeEpisodeTitle,
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
        $this->resumeSeason = null;
        $this->resumeEpisode = null;
        $this->resumeEpisodeTitle = null;
        $this->streamsLoading = false;
        $this->streamsFailed = false;
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
        // Remembered unconditionally (even on failure) so retryLoadStreams() always
        // knows what to re-fetch, regardless of whether this call came from a real
        // click or from the resume flow's wire:init.
        $this->resumeSeason = $season;
        $this->resumeEpisode = $episode;
        $this->streamsLoading = true;
        $this->streamsFailed = false;

        $integration = $this->integration;
        if (! $integration || ! $this->detailResult || ! $this->detailType || ! $this->detailId) {
            $this->streamsLoading = false;
            $this->streamsFailed = true;

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
            $this->streamsLoading = false;
            $this->streamsFailed = true;
            Notification::make()->danger()->title(__('Failed to load streams'))->body($e->getMessage())->send();

            return;
        }

        $streams = $data['streams'] ?? [];
        if (empty($streams)) {
            $this->streamsLoading = false;
            $this->streamsFailed = true;
            Notification::make()->warning()->title(__('No playable streams found'))->send();

            return;
        }

        // Always show the source picker rather than auto-playing a lone result — a
        // single "stream" is frequently a trailer/error placeholder from the addon
        // rather than a real playable source, so let the user confirm the choice.
        $this->streamChoices = $streams;
        $this->pendingWatchContext = $this->buildWatchContext($season, $episode, $episodeVideo);
        $this->streamsLoading = false;

        // Deliberately NOT re-mounting 'showDetail' here. The modal is always
        // already open by the time playStream() runs — via openDetail()'s picker,
        // the movie Watch button, or wire:init on the resume flow — and
        // mountAction() pushes onto Filament's mounted-actions stack
        // unconditionally rather than checking if it's already mounted. Doing so
        // anyway previously double-mounted the action; for the wire:init call
        // (no click to anchor Filament's focus-trap to) that made the underlying
        // page jump-scroll to the bottom, force-loading every lazy catalog row.
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
