<div @if ($guestMode && $queuePolling) wire:poll.{{ $this->queuePollInterval }}s="loadQueue" @endif>
    @if (! $detailOnly)
        @if ($this->integrationsForSearch->isNotEmpty())
            <div class="space-y-6">
                {{-- ── Search Bar ─────────────────────────────────────────────── --}}
                <form wire:submit.prevent="search" class="flex gap-2">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" class="flex-1">
                        <x-filament::input
                            type="text"
                            wire:model="searchTerm"
                            placeholder="{{ __('Search movies & TV series...') }}"
                        />
                    </x-filament::input.wrapper>
                    @if (strlen(trim($searchTerm)) >= 2)
                        <x-filament::button wire:click="clearSearch" color="gray" icon="heroicon-o-x-mark">
                            {{ __('Clear') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="search">
                        {{ __('Search') }}
                    </x-filament::button>
                </form>

                {{-- ── Search Results ──────────────────────────────────────────── --}}
                @if (strlen(trim($searchTerm)) >= 2 || $isSearching)
                    <x-filament::section
                        :collapsible="true"
                        compact
                        heading="{{ __('Search Results') }}"
                        icon="heroicon-o-magnifying-glass"
                        icon-color="gray"
                    >
                        @if (count($results) > 0)
                            <x-slot name="afterHeader">
                                @if (! empty($selectedGenres))
                                    <x-filament::badge color="primary">{{ count($this->filteredResults) }} / {{ count($results) }}</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray">{{ count($results) }}</x-filament::badge>
                                @endif
                            </x-slot>
                        @endif

                        <div>
                            @if ($isSearching)
                                <div class="flex items-center justify-center py-10">
                                    <x-filament::loading-indicator class="text-primary-500 h-5 w-5" />
                                    <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Searching...') }}</span>
                                </div>
                            @elseif (count($results) > 0)
                                {{-- Genre filter chips --}}
                                @if (count($this->availableGenres) > 0)
                                    <div class="mb-4 flex flex-wrap gap-1.5">
                                        @foreach ($this->availableGenres as $genre)
                                            <button
                                                type="button"
                                                wire:key="genre-{{ $genre }}"
                                                wire:click="toggleGenre('{{ $genre }}')"
                                                class="px-2.5 py-1 text-xs font-medium rounded-full border transition-colors
                                                {{
                                                    in_array($genre, $selectedGenres, true)
                                                    ? 'bg-primary-600 border-primary-600 text-white'
                                                    : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:border-primary-400 dark:hover:border-primary-500'
                                                }}"
                                            >
                                                {{ $genre }}
                                            </button>
                                        @endforeach
                                        @if (! empty($selectedGenres))
                                            <button
                                                type="button"
                                                wire:click="$set('selectedGenres', [])"
                                                class="rounded-full border border-gray-300 bg-transparent px-2.5 py-1 text-xs font-medium text-gray-500 transition-colors hover:border-gray-400 dark:border-gray-500 dark:text-gray-400"
                                            >
                                                {{ __('Clear') }}
                                            </button>
                                        @endif
                                    </div>
                                @endif
                                @php $filteredResults = $this->filteredResults; @endphp
                                @if (count($filteredResults) === 0)
                                    <div class="flex flex-col items-center justify-center py-10 text-center">
                                        <x-heroicon-o-funnel class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                                        <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ __('No results match the selected genres') }}
                                        </h4>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Try removing some genre filters.') }}
                                        </p>
                                    </div>
                                @else
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                        @foreach ($filteredResults as $index => $result)
                                            @php
                                                $isSonarr = ($result['integrationType'] ?? '') === 'sonarr';
                                                $inLibrary = ! empty($result['existsInLibrary']);
                                                $isDownloaded =
                                                    $inLibrary &&
                                                    ($isSonarr
                                                        ? ($result['episodeFileCount'] ?? 0) > 0
                                                        : $result['hasFile'] ?? false);
                                            @endphp
                                            <div
                                                wire:key="result-{{ $index }}"
                                                wire:click="openDetail({{ $index }})"
                                                class="group relative cursor-pointer overflow-hidden rounded-lg bg-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-xl dark:bg-gray-800"
                                            >
                                                <div class="relative aspect-[2/3]">
                                                    @if (! empty($result['poster']))
                                                        <img
                                                            src="{{ $result['poster'] }}"
                                                            alt="{{ $result['title'] ?? '' }}"
                                                            class="h-full w-full object-cover"
                                                            loading="lazy"
                                                        />
                                                    @else
                                                        <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                                                            @if ($isSonarr)
                                                                <x-heroicon-o-tv class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                                                            @else
                                                                <x-heroicon-o-film class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                                                            @endif
                                                        </div>
                                                    @endif
                                                    <span class="absolute top-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-xs font-semibold text-white">
                                                        {{ $isSonarr ? __('TV') : __('Movie') }}
                                                    </span>
                                                    @if ($isDownloaded)
                                                        <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-green-500 shadow-sm">
                                                            <x-heroicon-s-check class="h-3.5 w-3.5 text-white" />
                                                        </span>
                                                    @elseif ($inLibrary)
                                                        <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 shadow-sm">
                                                            <x-heroicon-s-bookmark class="h-3.5 w-3.5 text-white" />
                                                        </span>
                                                    @endif
                                                    @if (! empty($result['rating']['value']))
                                                        <span class="absolute right-2 bottom-2 flex items-center gap-0.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-semibold text-yellow-400">
                                                            <x-heroicon-s-star class="h-3 w-3" />
                                                            {{ $result['rating']['value'] }}
                                                        </span>
                                                    @endif
                                                    @if (! empty($result['integrationName']))
                                                        <span
                                                            class="absolute bottom-2 left-2 rounded px-1.5 py-0.5 text-xs font-medium text-white shadow-sm"
                                                            style="background:{{ $isSonarr ? 'oklch(0.588 0.158 241.966)' : 'oklch(0.558 0.288 302.321)' }}"
                                                        >{{ $result['integrationName'] }}</span>
                                                    @endif
                                                    <div class="absolute inset-0 flex flex-col justify-end gap-1 bg-gradient-to-t from-black/95 via-black/65 to-black/20 p-3 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                                                        <h3 class="line-clamp-2 text-sm leading-tight font-semibold text-white">
                                                            {{ $result['title'] ?? '' }}
                                                            @if (! empty($result['year']))
                                                                <span class="font-normal text-white/60">({{ $result['year'] }})</span>
                                                            @endif
                                                        </h3>
                                                        @if (! empty($result['overview']))
                                                            <p class="line-clamp-2 text-xs text-white/55">
                                                                {{ $result['overview'] }}
                                                            </p>
                                                        @endif
                                                        <div class="mt-1.5">
                                                            @if ($isDownloaded)
                                                                <span class="flex items-center gap-1 text-xs font-medium text-green-400">
                                                                    <x-heroicon-s-check class="h-3.5 w-3.5" />
                                                                    @if ($isSonarr && ($result['totalEpisodeCount'] ?? 0) > 0)
                                                                        {{ $result['episodeFileCount'] }}/{{ $result['totalEpisodeCount'] }}
                                                                        {{ __('eps') }}
                                                                    @else
                                                                        {{ __('Downloaded') }}
                                                                    @endif
                                                                </span>
                                                            @elseif ($inLibrary)
                                                                <span class="flex items-center gap-1 text-xs font-medium text-amber-400">
                                                                    <x-heroicon-s-bookmark class="h-3.5 w-3.5" />
                                                                    {{ __('Monitored') }}
                                                                </span>
                                                            @elseif ($isSonarr)
                                                                <span class="bg-primary-600 block w-full rounded-md px-2 py-1.5 text-center text-xs font-medium text-white">
                                                                    {{ __('Select Seasons') }}
                                                                </span>
                                                            @else
                                                                <button
                                                                    wire:click.stop="request({{ $index }})"
                                                                    wire:loading.attr="disabled"
                                                                    wire:target="request"
                                                                    class="bg-primary-600 hover:bg-primary-700 block w-full rounded-md px-2 py-1.5 text-center text-xs font-medium text-white transition-colors disabled:opacity-60"
                                                                >
                                                                    {{ __('Request Movie') }}
                                                                </button>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @else
                                <div class="flex flex-col items-center justify-center py-10 text-center">
                                    <x-heroicon-o-magnifying-glass class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                                    <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ __('No results found') }}
                                    </h4>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Try a different search term.') }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    </x-filament::section>

                @endif

                {{-- ── Discover Sections ────────────────────────────────────────── --}}
                @if ($tmdbConfigured && strlen(trim($searchTerm)) < 2)
                    <livewire:arr-discover :guestMode="$guestMode" :guestIntegrationIds="$guestIntegrationIds" />
                @elseif (! $tmdbConfigured && strlen(trim($searchTerm)) < 2)
                    {{-- No TMDB: search prompt --}}
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <div class="flex gap-3 text-gray-300 dark:text-gray-600">
                            <x-heroicon-o-tv class="h-10 w-10" />
                            <x-heroicon-o-film class="h-10 w-10" />
                        </div>
                        <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ __('Search movies & TV series') }}
                        </h4>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Enter a title to search across your Sonarr and Radarr servers.') }}
                        </p>
                    </div>
                @endif
            </div>

            {{-- ── Guest Download Queue ─────────────────────────────────────── --}}
            @if ($queue && $guestMode)
                <div class="mt-6">
                    <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                        <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                        {{ __('Download Queue') }}
                    </h3>
                    <div class="space-y-2">
                        @foreach ($queue as $item)
                            <div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                <div class="flex items-center justify-between">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ $item['title'] ?? __('Unknown') }}
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $item['status'] ?? '' }}
                                            @if ($item['timeLeft'] ?? null)
                                                · {{ __(':time left', ['time' => $item['timeLeft']]) }}
                                            @endif
                                            @if (! empty($item['server']))
                                                · {{ $item['server'] }}
                                            @endif
                                        </p>
                                    </div>
                                    <span class="text-primary-600 dark:text-primary-400 ml-3 text-xs font-medium">
                                        {{ $item['progress'] ?? 0 }}%
                                    </span>
                                </div>
                                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                    <div
                                        class="bg-primary-600 h-1.5 rounded-full transition-all duration-300"
                                        style="width: {{ $item['progress'] ?? 0 }}%"
                                    ></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @else
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <x-heroicon-o-magnifying-glass-circle class="h-12 w-12 text-gray-300 dark:text-gray-600" />
                <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $guestMode ? __('No integrations available') : __('No Sonarr or Radarr integrations configured') }}
                </h4>
                @unless ($guestMode)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Add a Sonarr or Radarr integration in Settings to start requesting content.') }}
                    </p>
                @endunless
            </div>

        @endif

    @endif
    {{-- /detailOnly --}}

    <x-filament-actions::modals />
</div>
