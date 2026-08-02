@if (empty($items))
    <p class="py-8 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('No filmography available.') }}</p>
@else
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
        @foreach ($items as $item)
            @php
                $isTv = ($item['media_type'] ?? 'movie') === 'tv';
                $tmdbId = (int) ($item['tmdb_id'] ?? 0);
                $mediaType = $item['media_type'] ?? 'movie';
            @endphp
            <div
                wire:click="openFilmographyItem({{ $tmdbId }}, '{{ $mediaType }}')"
                class="group relative cursor-pointer overflow-hidden rounded-lg bg-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-xl dark:bg-gray-800"
            >
                <div class="relative aspect-[2/3]">
                    @if (! empty($item['poster_url']))
                        <img
                            src="{{ $item['poster_url'] }}"
                            alt="{{ $item['title'] ?? '' }}"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                    @else
                        <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                            @if ($isTv)
                                <x-heroicon-o-tv class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                            @else
                                <x-heroicon-o-film class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                            @endif
                        </div>
                    @endif
                    <span class="absolute top-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-xs font-semibold text-white">
                        {{ $isTv ? __('TV') : __('Movie') }}
                    </span>
                    @if (! empty($item['year']))
                        <span class="absolute top-2 right-2 rounded bg-black/60 px-1.5 py-0.5 text-xs font-medium text-white">
                            {{ $item['year'] }}
                        </span>
                    @endif
                    <div class="absolute inset-0 flex flex-col justify-end gap-1 bg-gradient-to-t from-black/95 via-black/65 to-black/20 p-3 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                        <h3 class="line-clamp-2 text-sm leading-tight font-semibold text-white">
                            {{ $item['title'] ?? '' }}
                        </h3>
                        @if (! empty($item['character']))
                            <p class="line-clamp-1 text-xs text-white/70">{{ __('as') }} {{ $item['character'] }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
