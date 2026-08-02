<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div wire:poll.5s.visible>
        @php($stats = $getStats())
        @if (! empty($stats))
            <div class="">
                @if (isset($stats['proxy_enabled']) && $stats['proxy_enabled'])
                    <!-- Proxy Streams Section -->
                    <div class="pb-4">
                        <h3 class="mb-3 flex items-center text-sm font-semibold text-gray-900 dark:text-gray-100">
                            <div class="mr-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800">
                                <x-heroicon-s-signal class="h-4 w-4 text-blue-500" />
                            </div>
                            Proxy Usage
                        </h3>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <!-- Stream Count -->
                            <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Active Connections</span>
                                    <div class="text-right">
                                        <div class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                            {{ $stats['active_connections'] ?? '0' }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Max Streams Status -->
                            <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Max Reached</span>
                                    <div class="text-right">
                                        @if ($stats['max_streams_reached'])
                                            <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-200">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                                </svg>
                                                Yes
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                </svg>
                                                No
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if (isset($stats['xtream_info']))
                    <!-- Xtream Info Section -->
                    <div class="pb-4">
                        <h3 class="mb-3 flex items-center text-sm font-semibold text-gray-900 dark:text-gray-100">
                            <div class="mr-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800">
                                <x-heroicon-s-bolt class="h-4 w-4 text-green-500" />
                            </div>
                            Xtream Provider Details
                        </h3>

                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <!-- Active Connections -->
                            <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Active Connections</span>
                                    <div class="text-lg font-bold text-gray-900 dark:text-gray-100">
                                        {{ $stats['xtream_info']['active_connections'] }}
                                    </div>
                                </div>
                            </div>

                            <!-- Expiration Info -->
                            <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Expires</span>
                                        <div class="text-lg leading-4 font-bold text-gray-900 dark:text-gray-100">
                                            {{ $stats['xtream_info']['expires'] }}
                                        </div>
                                    </div>
                                    <p class="text-right text-xs text-gray-500 dark:text-gray-400">
                                        {{ $stats['xtream_info']['expires_description'] }}
                                    </p>
                                </div>
                            </div>

                            <!-- Max Streams Status -->
                            <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Max Reached</span>
                                    <div class="text-right">
                                        @if ($stats['xtream_info']['max_streams_reached'])
                                            <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-200">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                                </svg>
                                                Yes
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                </svg>
                                                No
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Channel & Series Stats Section -->
                <div>
                    <h3 class="mb-3 flex items-center text-sm font-semibold text-gray-900 dark:text-gray-100">
                        <div class="mr-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800">
                            <x-heroicon-s-play class="h-4 w-4 text-green-500" />
                        </div>
                        Channel & Series
                    </h3>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                        <!-- Channels -->
                        <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Live</span>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $stats['channel_count'] ?? 0 }}
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_channel_count'] ?? 0 }}</span>
                            </div>
                        </div>
                        <!-- VOD -->
                        <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">VOD</span>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $stats['vod_count'] ?? 0 }}
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_vod_count'] ?? 0 }}</span>
                            </div>
                        </div>
                        <!-- Series -->
                        <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Series</span>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $stats['series_count'] ?? 0 }}
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Enabled: {{ $stats['enabled_series_count'] ?? 0 }}</span>
                            </div>
                        </div>
                        <!-- Groups -->
                        <div class="rounded-md bg-gray-100 p-3 dark:bg-gray-800">
                            <div class="flex flex-col items-center">
                                <span class="text-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">Groups</span>
                                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                                    {{ $stats['group_count'] ?? 0 }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-dynamic-component>
