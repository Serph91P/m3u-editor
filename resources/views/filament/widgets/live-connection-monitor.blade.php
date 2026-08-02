<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <span>Live Connection Monitor</span>
                <div class="flex items-center space-x-2">
                    <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                        <svg class="mr-1 h-2 w-2 animate-pulse" fill="currentColor" viewBox="0 0 8 8">
                            <circle cx="4" cy="4" r="3" />
                        </svg>
                        Live
                    </span>
                    <span class="text-xs text-gray-500">Updates every 5s</span>
                </div>
            </div>
        </x-slot>

        <div class="space-y-6">
            <!-- Connection Statistics Bar -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="rounded-lg bg-blue-50 p-3 dark:bg-blue-900/20">
                    <div class="text-xs text-blue-600 dark:text-blue-400">Last Minute</div>
                    <div class="text-lg font-semibold text-blue-900 dark:text-blue-100">
                        {{ $connectionStats['last_minute'] ?? 0 }}
                    </div>
                </div>
                <div class="rounded-lg bg-green-50 p-3 dark:bg-green-900/20">
                    <div class="text-xs text-green-600 dark:text-green-400">Last 5 Minutes</div>
                    <div class="text-lg font-semibold text-green-900 dark:text-green-100">
                        {{ $connectionStats['last_5_minutes'] ?? 0 }}
                    </div>
                </div>
                <div class="rounded-lg bg-purple-50 p-3 dark:bg-purple-900/20">
                    <div class="text-xs text-purple-600 dark:text-purple-400">Last Hour</div>
                    <div class="text-lg font-semibold text-purple-900 dark:text-purple-100">
                        {{ $connectionStats['last_hour'] ?? 0 }}
                    </div>
                </div>
                <div class="rounded-lg bg-yellow-50 p-3 dark:bg-yellow-900/20">
                    <div class="text-xs text-yellow-600 dark:text-yellow-400">Active Now</div>
                    <div class="text-lg font-semibold text-yellow-900 dark:text-yellow-100">
                        {{ $connectionStats['active_now'] ?? 0 }}
                    </div>
                </div>
            </div>

            <!-- Active Connections Summary -->
            <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                <div class="mb-3 flex items-center justify-between">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">Current Activity</h4>
                    <div class="flex items-center space-x-4 text-xs text-gray-500">
                        <span>{{ $activeConnections['total_active_streams'] ?? 0 }} streams</span>
                        <span>{{ $activeConnections['total_clients'] ?? 0 }} clients</span>
                        <span>
                            @php
                                $bandwidth = $activeConnections['total_bandwidth'] ?? 0;
                                echo $bandwidth > 1000 ? round($bandwidth / 1000, 1).' Mbps' : $bandwidth.' kbps';
                            @endphp
                        </span>
                    </div>
                </div>

                @if (! empty($activeConnections['streams_by_format']))
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        @foreach ($activeConnections['streams_by_format'] as $format => $data)
                            <div class="flex items-center justify-between rounded bg-white p-2 dark:bg-gray-700">
                                <span class="px-2 py-1 text-xs font-medium rounded {{ $format === 'hls' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : ($format === 'dash' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-600 dark:text-gray-300') }}">
                                    {{ strtoupper($format) }}
                                </span>
                                <div class="text-xs text-gray-600 dark:text-gray-400">
                                    {{ $data['streams'] }} streams, {{ $data['clients'] }} clients
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-2 text-center text-gray-500 dark:text-gray-400">
                        <p class="text-sm">No active streams</p>
                    </div>
                @endif
            </div>

            <!-- Recent Connections -->
            <div>
                <h4 class="mb-3 text-sm font-medium text-gray-900 dark:text-white">
                    Recent Connections (Last 5 minutes)
                </h4>

                @if (! empty($recentConnections))
                    <div class="max-h-80 space-y-2 overflow-y-auto">
                        @foreach ($recentConnections as $connection)
                            <div class="flex items-center justify-between p-3 bg-white dark:bg-gray-700 rounded-lg border-l-4 {{ $connection['status'] === 'active' ? 'border-green-500' : 'border-gray-300' }}">
                                <div class="flex min-w-0 flex-1 items-center space-x-3">
                                    <!-- Status Indicator -->
                                    <div class="flex-shrink-0">
                                        <div class="w-3 h-3 rounded-full {{ $connection['status'] === 'active' ? 'bg-green-500 animate-pulse' : 'bg-gray-400' }}"></div>
                                    </div>

                                    <!-- Connection Info -->
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center space-x-2">
                                            <span class="truncate text-sm font-medium text-gray-900 dark:text-white">
                                                {{ $connection['stream_title'] }}
                                            </span>
                                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-600 dark:text-gray-300">
                                                {{ $connection['stream_id'] }}
                                            </span>
                                        </div>
                                        <div class="mt-1 flex items-center space-x-4 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="flex items-center">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                                                </svg>
                                                {{ $connection['ip'] }}
                                            </span>
                                            <span class="flex items-center">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                                </svg>
                                                {{ \Carbon\Carbon::parse($connection['connected_at'])->diffForHumans() }}
                                            </span>
                                            <span class="flex items-center">
                                                <svg class="mr-1 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path>
                                                </svg>
                                                {{ $connection['duration'] < 60 ? $connection['duration'] . 's' : gmdate('i:s', $connection['duration']) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Bandwidth Badge -->
                                <div class="ml-3 flex-shrink-0">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $connection['bandwidth'] > 2000 ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300' : ($connection['bandwidth'] > 1000 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300') }}">
                                        {{ $connection['bandwidth'] > 1000 ? round($connection['bandwidth'] / 1000, 1) . ' Mbps' : $connection['bandwidth'] . ' kbps' }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-8 text-center text-gray-500 dark:text-gray-400">
                        <svg class="mx-auto mb-4 h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"></path>
                        </svg>
                        <p class="text-sm">No recent connections</p>
                        <p class="text-xs">New connections will appear here in real-time</p>
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
