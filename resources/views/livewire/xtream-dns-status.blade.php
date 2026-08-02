<div>
    @php($results = $this->getResults())
    @php($hasMultiple = $this->hasMultipleUrls())

    @if ($hasMultiple)
        <div class="mt-4" wire:poll.5s>
            <div class="mb-3 flex items-center justify-between">
                <span class="text-sm leading-6 font-medium text-gray-950 dark:text-white"> Server DNS Status </span>
                <x-filament::button size="xs" color="gray" wire:click="checkAll" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="checkAll">Check All</span>
                    <span wire:loading wire:target="checkAll">Checking...</span>
                </x-filament::button>
            </div>

            @if (empty($results))
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Checking server status...</p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($results as $result)
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($result['reachable'])
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                        Online
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                        Offline
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {{ $result['url'] }}
                                        @if ($result['is_primary'] ?? false)
                                            <span class="text-xs text-blue-600 dark:text-blue-400">(Primary)</span>
                                        @endif
                                    </p>
                                    @if ($result['reachable'])
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            Response: {{ $result['response_time_ms'] }}ms
                                        </p>
                                    @elseif ($result['error'] ?? false)
                                        <p class="truncate text-xs text-red-500 dark:text-red-400">
                                            {{ Str::limit($result['error'], 80) }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
