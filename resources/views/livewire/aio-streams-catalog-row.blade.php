<div x-intersect="$wire.loadIfVisible()">
    <x-filament::section :collapsible="true" compact heading="{{ $catalogName }}"
        icon="{{ $catalogType == 'movie' ? 'heroicon-o-film' : 'heroicon-o-tv' }}" icon-color="gray">
        @if ($loaded && !empty($items))
            <x-slot name="afterHeader">
                <x-filament::badge color="gray">{{ count($items) }}</x-filament::badge>
            </x-slot>
        @endif

        @if (!$loaded)
            <div class="flex items-center justify-center py-10">
                <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
            </div>
        @elseif ($loadFailed)
            <div class="flex flex-col items-center justify-center py-6 text-center">
                <x-heroicon-o-exclamation-triangle class="w-8 h-8 text-gray-300 dark:text-gray-600" />
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Failed to load this catalog') }}</p>
            </div>
        @elseif (empty($items))
            <p class="text-xs text-gray-400 dark:text-gray-500 text-center py-4">{{ __('No items in this catalog') }}
            </p>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                @foreach ($items as $item)
                    @include('livewire.partials.aiostreams-card', ['item' => $item])
                @endforeach
            </div>
        @endif
    </x-filament::section>
</div>
