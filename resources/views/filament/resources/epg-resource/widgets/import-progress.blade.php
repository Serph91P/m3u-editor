<x-filament-widgets::widget>
    <div wire:poll.5s.visible>
        @php($data = $this->getProgressData())
        @if ($data['processing'] && $data['progress'] < 100)
            <div class="relative mb-2 h-5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                <div
                    class="bg-primary-600 absolute top-0 left-0 h-5 transition-all duration-700 ease-in-out"
                    style="width: {{ $data['progress'] }}%"
                ></div>
                <div class="absolute top-0 left-0 flex h-5 w-full items-center justify-center">
                    <span class="text-primary-900 dark:text-primary-100 text-xs font-medium select-none">Sync Progress: <strong>{{ $data['progress'] }}%</strong></span>
                </div>
            </div>
        @endif
        @if ($data['processing'] && $data['sdProgress'] && $data['sdProgress'] < 100)
            <div class="relative h-5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                <div
                    class="bg-primary-600 absolute top-0 left-0 h-5 transition-all duration-700 ease-in-out"
                    style="width: {{ $data['sdProgress'] }}%"
                ></div>
                <div class="absolute top-0 left-0 flex h-5 w-full items-center justify-center">
                    <span class="text-primary-900 dark:text-primary-100 text-xs font-medium select-none">Schedules Direct Progress: <strong>{{ $data['sdProgress'] }}%</strong></span>
                </div>
            </div>
        @endif
        @if ($data['processing'] && $data['cacheProgress'] && $data['cacheProgress'] < 100)
            <div class="relative h-5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                <div
                    class="bg-primary-600 absolute top-0 left-0 h-5 transition-all duration-700 ease-in-out"
                    style="width: {{ $data['cacheProgress'] }}%"
                ></div>
                <div class="absolute top-0 left-0 flex h-5 w-full items-center justify-center">
                    <span class="text-primary-900 dark:text-primary-100 text-xs font-medium select-none">Cache Progress: <strong>{{ $data['cacheProgress'] }}%</strong></span>
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
