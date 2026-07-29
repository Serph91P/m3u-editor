<div>

    @if (!$this->integration)
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <x-heroicon-o-exclamation-triangle class="w-12 h-12 text-gray-300 dark:text-gray-600" />
            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ __('AIOStreams integration not found or disabled') }}</h4>
        </div>
    @else
        <div class="space-y-6">

            {{-- ── Search Bar & Type Filter ───────────────────────────────── --}}
            <div class="flex flex-col sm:flex-row gap-2">
                <form wire:submit.prevent="search" class="flex gap-2 flex-1">
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass" class="flex-1">
                        <x-filament::input type="text" wire:model="searchTerm"
                            placeholder="{{ __('Search movies & TV series...') }}" />
                    </x-filament::input.wrapper>
                    @if (mb_strlen(trim($searchTerm)) >= 2)
                        <x-filament::button wire:click="clearSearch" color="gray" icon="heroicon-o-x-mark">
                            {{ __('Clear') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="search">
                        {{ __('Search') }}
                    </x-filament::button>
                </form>

                <div class="flex gap-1.5">
                    @foreach (['all' => __('All'), 'movie' => __('Movies'), 'series' => __('Series')] as $value => $label)
                        <x-filament::button size="sm" wire:click="$set('typeFilter', '{{ $value }}')"
                            :icon="$value === 'movie' ? 'heroicon-o-film' : ($value === 'series' ? 'heroicon-o-tv' : 'heroicon-o-rectangle-stack')" :color="$typeFilter === $value ? 'primary' : 'gray'" :outlined="$typeFilter !== $value">
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>

            {{-- ── Search Results ──────────────────────────────────────────── --}}
            @if (mb_strlen(trim($searchTerm)) >= 2 || $isSearching)
                <x-filament::section :collapsible="true" compact heading="{{ __('Search Results') }}"
                    icon="heroicon-o-magnifying-glass" icon-color="gray">
                    @if (count($searchResults) > 0)
                        <x-slot name="afterHeader">
                            <x-filament::badge color="gray">{{ count($searchResults) }}</x-filament::badge>
                        </x-slot>
                    @endif

                    @if ($isSearching)
                        <div class="flex items-center justify-center py-10">
                            <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                            <span class="ml-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Searching...') }}</span>
                        </div>
                    @elseif(count($searchResults) > 0)
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                            @foreach ($searchResults as $item)
                                @include('livewire.partials.aiostreams-card', ['item' => $item])
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center py-10 text-center">
                            <x-heroicon-o-magnifying-glass class="w-10 h-10 text-gray-300 dark:text-gray-600" />
                            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ __('No results found') }}</h4>
                        </div>
                    @endif
                </x-filament::section>
            @else
                {{-- ── Continue Watching ───────────────────────────────────── --}}
                @if ($this->continueWatching->isNotEmpty())
                    <x-filament::section :collapsible="true" compact heading="{{ __('Continue Watching') }}"
                        icon="heroicon-o-play-pause" icon-color="gray">
                        <x-slot name="afterHeader">
                            <x-filament::badge
                                color="gray">{{ $this->continueWatching->count() }}</x-filament::badge>
                        </x-slot>

                        <div class="flex gap-3 overflow-x-auto pb-1">
                            @foreach ($this->continueWatching as $progress)
                                @php
                                    $pct = $progress->duration_seconds
                                        ? min(
                                            100,
                                            (int) round(
                                                ($progress->position_seconds / $progress->duration_seconds) * 100,
                                            ),
                                        )
                                        : 0;
                                    $isEpisode = (bool) $progress->season_number;
                                    $durationLabel = null;
                                    if ($progress->duration_seconds) {
                                        $h = intdiv($progress->duration_seconds, 3600);
                                        $m = intdiv($progress->duration_seconds % 3600, 60);
                                        $durationLabel = $h > 0 ? "{$h}h {$m}m" : "{$m}m";
                                    }
                                @endphp
                                <div wire:key="continue-{{ $progress->id }}"
                                    wire:click="resumeWatch({{ $progress->id }})"
                                    class="group cursor-pointer rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800 flex-shrink-0 w-64">
                                    <div class="relative aspect-video">
                                        @if ($progress->backdrop_url || $progress->thumbnail_url)
                                            <img src="{{ $progress->backdrop_url ?: $progress->thumbnail_url }}"
                                                alt="{{ $progress->title }}" class="w-full h-full object-cover"
                                                loading="lazy">
                                        @else
                                            <div
                                                class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                                <x-heroicon-o-film class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                                            </div>
                                        @endif

                                        <span
                                            class="absolute top-2 right-2 px-1.5 py-0.5 rounded bg-primary-600 text-white text-xs font-semibold">
                                            {{ $pct }}%
                                        </span>

                                        <div class="absolute bottom-2 left-2">
                                            @if ($isEpisode)
                                                <span
                                                    class="px-1.5 py-0.5 text-xs font-medium rounded bg-black/70 text-white">
                                                    {{ __('S:s E:e', ['s' => $progress->season_number, 'e' => $progress->episode_number]) }}
                                                </span>
                                            @elseif ($progress->year)
                                                <span
                                                    class="px-1.5 py-0.5 text-xs font-medium rounded bg-black/70 text-white">
                                                    {{ $progress->year }}
                                                </span>
                                            @endif
                                        </div>

                                        <div class="absolute bottom-2 right-2 flex items-center gap-1">
                                            @if ($progress->rating)
                                                <span
                                                    class="flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-black/70 text-yellow-400 text-xs font-semibold">
                                                    <x-heroicon-s-star class="w-3 h-3" />
                                                    {{ $progress->rating }}
                                                </span>
                                            @endif
                                            @if ($durationLabel)
                                                <span
                                                    class="px-1.5 py-0.5 rounded bg-black/70 text-white text-xs font-medium">
                                                    {{ $durationLabel }}
                                                </span>
                                            @endif
                                        </div>

                                        <div class="absolute bottom-0 left-0 right-0 h-1 bg-black/40">
                                            <div class="h-full bg-primary-500" style="width: {{ $pct }}%">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="p-2">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                            {{ $progress->title }}</p>
                                        @if ($progress->episode_title)
                                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                                {{ $progress->episode_title }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif

                {{-- ── Catalog Rows — each section's items load only once scrolled into view ──── --}}
                @if (empty($this->catalogs))
                    <div class="flex flex-col items-center justify-center py-12 text-center">
                        <x-heroicon-o-film class="w-10 h-10 text-gray-300 dark:text-gray-600" />
                        <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ __('No catalogs available') }}</h4>
                    </div>
                @else
                    <div class="space-y-6">
                        @foreach ($this->catalogs as $catalog)
                            <livewire:aio-streams-catalog-row :integration-id="$integrationId" :catalog-id="$catalog['id']" :catalog-type="$catalog['type']"
                                :catalog-name="$catalog['name']" :key="'aiostreams-row-' .
                                    $integrationId .
                                    '-' .
                                    $catalog['id'] .
                                    '-' .
                                    $catalog['type'] .
                                    '-' .
                                    $typeFilter" />
                        @endforeach
                    </div>
                @endif
            @endif

        </div>

    @endif

    <x-filament-actions::modals />

</div>
