@php
    $isSeries = ($item['type'] ?? '') === 'series';
@endphp
<div wire:key="item-{{ $item['id'] }}-{{ $item['type'] ?? '' }}"
    wire:click="$dispatch('openAioDetail', { type: '{{ $item['type'] ?? 'movie' }}', id: '{{ addslashes($item['id']) }}' })"
    class="group relative cursor-pointer rounded-lg overflow-hidden shadow-sm hover:shadow-xl transition-shadow duration-200 bg-gray-200 dark:bg-gray-800">
    <div class="relative aspect-[2/3]">
        @if (!empty($item['poster']))
            <img src="{{ $item['poster'] }}" alt="{{ $item['name'] ?? '' }}" class="w-full h-full object-cover"
                loading="lazy">
        @else
            <div class="w-full h-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                @if ($isSeries)
                    <x-heroicon-o-tv class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                @else
                    <x-heroicon-o-film class="w-10 h-10 text-gray-400 dark:text-gray-500" />
                @endif
            </div>
        @endif
        <span class="absolute top-2 left-2 px-1.5 py-0.5 text-xs font-semibold rounded bg-black/60 text-white">
            {{ $isSeries ? __('Series') : __('Movie') }}
        </span>
        @if (!empty($item['imdbRating']))
            <span
                class="absolute bottom-2 right-2 flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-black/70 text-yellow-400 text-xs font-semibold">
                <x-heroicon-s-star class="w-3 h-3" />
                {{ $item['imdbRating'] }}
            </span>
        @endif
        <div
            class="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-200 bg-gradient-to-t from-black/95 via-black/65 to-black/20 flex flex-col justify-end p-3 gap-1">
            <h3 class="text-white font-semibold text-sm leading-tight line-clamp-2">
                {{ $item['name'] ?? '' }}
                @if (!empty($item['releaseInfo']))
                    <span class="text-white/60 font-normal">({{ $item['releaseInfo'] }})</span>
                @endif
            </h3>
            @if (!empty($item['description']))
                <p class="text-white/55 text-xs line-clamp-2">{{ $item['description'] }}</p>
            @endif
        </div>
    </div>
</div>
