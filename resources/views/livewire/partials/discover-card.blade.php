<div
    wire:click="requestFromDiscover({{ $item['tmdb_id'] }}, '{{ $item['media_type'] }}')"
    wire:loading.class="opacity-50 pointer-events-none"
    wire:target="requestFromDiscover"
    class="group relative cursor-pointer overflow-hidden rounded-lg bg-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-xl dark:bg-gray-800"
>
    <div class="relative aspect-[2/3]">
        @if (! empty($item['poster_url']))
            <img
                src="{{ $item['poster_url'] }}"
                alt="{{ $item['title'] }}"
                class="h-full w-full object-cover"
                loading="lazy"
            />
        @else
            <div class="flex h-full w-full items-center justify-center bg-gray-200 dark:bg-gray-700">
                @if ($item['media_type'] === 'tv')
                    <x-heroicon-o-tv class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                @else
                    <x-heroicon-o-film class="h-10 w-10 text-gray-400 dark:text-gray-500" />
                @endif
            </div>
        @endif
        <span class="absolute top-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-xs font-semibold text-white">
            {{ $item['media_type'] === 'tv' ? __('TV') : __('Movie') }}
        </span>
        @if (! empty($item['isDownloaded']))
            <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-green-500 shadow-sm">
                <x-heroicon-s-check class="h-3.5 w-3.5 text-white" />
            </span>
        @elseif (! empty($item['existsInLibrary']))
            <span class="absolute top-2 right-2 flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 shadow-sm">
                <x-heroicon-s-bookmark class="h-3.5 w-3.5 text-white" />
            </span>
        @endif
        @if (! empty($item['vote_average']))
            <span class="absolute right-2 bottom-2 flex items-center gap-0.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-semibold text-yellow-400">
                <x-heroicon-s-star class="h-3 w-3" />
                {{ $item['vote_average'] }}
            </span>
        @endif
        <div class="absolute inset-0 flex flex-col justify-end gap-1 bg-gradient-to-t from-black/95 via-black/65 to-black/20 p-3 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
            <h3 class="line-clamp-2 text-sm leading-tight font-semibold text-white">
                {{ $item['title'] }}
                @if (! empty($item['year']))
                    <span class="font-normal text-white/60">({{ $item['year'] }})</span>
                @endif
            </h3>
            @if (! empty($item['overview']))
                <p class="line-clamp-2 text-xs text-white/55">{{ $item['overview'] }}</p>
            @endif
            <div class="mt-1.5">
                @if (! empty($item['isDownloaded']))
                    <span class="flex items-center gap-1 text-xs font-medium text-green-400">
                        <x-heroicon-s-check class="h-3.5 w-3.5" />
                        {{ __('Downloaded') }}
                    </span>
                @elseif (! empty($item['existsInLibrary']))
                    <span class="flex items-center gap-1 text-xs font-medium text-amber-400">
                        <x-heroicon-s-bookmark class="h-3.5 w-3.5" />
                        {{ __('Monitored') }}
                    </span>
                @else
                    <span class="bg-primary-600 block w-full rounded-md px-2 py-1.5 text-center text-xs font-medium text-white">
                        {{ $item['media_type'] === 'tv' ? __('Select Seasons') : __('Request Movie') }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
