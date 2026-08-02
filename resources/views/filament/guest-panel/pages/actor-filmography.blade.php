<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <div class="h-20 w-20 flex-shrink-0 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                @if (! empty($person['photo']))
                    <img src="{{ $person['photo'] }}" alt="{{ $person['name'] }}" class="h-full w-full object-cover" />
                @else
                    <div class="flex h-full w-full items-center justify-center">
                        <x-heroicon-o-user class="h-8 w-8 text-gray-400" />
                    </div>
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $person['name'] ?? $name ?: __('Actor') }}
                </h1>
                @if (! empty($person['bio']))
                    <p class="mt-1 line-clamp-3 text-sm text-gray-600 dark:text-gray-400">{{ $person['bio'] }}</p>
                @endif
            </div>
        </div>

        @include('filament.partials.filmography-grid', ['items' => $filmography])
    </div>

    @if (! empty($guestIntegrationIds))
        <livewire:arr-search
            :guest-integration-ids="$guestIntegrationIds"
            :guest-mode="true"
            :detail-only="true"
            wire:key="filmography-arr-search-guest"
        />
    @endif
</x-filament-panels::page>
