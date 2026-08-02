<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <span>{{ __('Quick Actions') }}</span>
                <div class="flex items-center space-x-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $system_health['redis_connected'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{ $system_health['redis_connected'] ? __('Online') : __('Offline') }}
                    </span>
                </div>
            </div>
        </x-slot>

        <div class="space-y-6">
            <!-- System Overview -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="rounded-lg bg-blue-50 p-3 text-center dark:bg-blue-900/20">
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                        {{ $stats['active_streams'] }}
                    </div>
                    <div class="text-xs text-blue-600 dark:text-blue-400">{{ __('Active Streams') }}</div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ __('of :n total', ['n' => $stats['total_streams']]) }}
                    </div>
                </div>

                <div class="text-center p-3 {{ $stats['unhealthy_streams'] > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} rounded-lg">
                    <div class="text-2xl font-bold {{ $stats['unhealthy_streams'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ $stats['unhealthy_streams'] }}
                    </div>
                    <div class="text-xs {{ $stats['unhealthy_streams'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ __('Unhealthy Streams') }}
                    </div>
                </div>

                <div class="text-center p-3 {{ $stats['idle_streams'] > 0 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-green-50 dark:bg-green-900/20' }} rounded-lg">
                    <div class="text-2xl font-bold {{ $stats['idle_streams'] > 0 ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ $stats['idle_streams'] }}
                    </div>
                    <div class="text-xs {{ $stats['idle_streams'] > 0 ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ __('Idle Streams') }}
                    </div>
                </div>

                <div class="text-center p-3 {{ $system_health['memory_usage'] > 80 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }} rounded-lg">
                    <div class="text-2xl font-bold {{ $system_health['memory_usage'] > 80 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ $system_health['memory_usage'] }}%
                    </div>
                    <div class="text-xs {{ $system_health['memory_usage'] > 80 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        {{ __('Memory Usage') }}
                    </div>
                </div>
            </div>

            <!-- Quick Action Buttons -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <!-- Cleanup Streams -->
                <button
                    wire:click="cleanupStreams"
                    class="group flex flex-col items-center rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700"
                >
                    <div class="mb-3 rounded-lg bg-blue-100 p-2 transition-colors group-hover:bg-blue-200 dark:bg-blue-900/50 dark:group-hover:bg-blue-900/70">
                        <svg
                            class="h-6 w-6 text-blue-600 dark:text-blue-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            ></path>
                        </svg>
                    </div>
                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ __('Cleanup Streams') }}</span>
                    <span class="mt-1 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('Remove inactive streams and clients') }}</span>
                </button>
            </div>

            <!-- System Resources Bar -->
            <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                <h4 class="mb-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('System Resources') }}</h4>
                <div class="space-y-3">
                    <!-- Memory Usage -->
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Memory') }}</span>
                        <div class="flex items-center space-x-2">
                            <div class="h-2 w-32 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-2 rounded-full {{ $system_health['memory_usage'] > 80 ? 'bg-red-500' : ($system_health['memory_usage'] > 60 ? 'bg-yellow-500' : 'bg-green-500') }}"
                                    style="width: {{ $system_health['memory_usage'] }}%"
                                ></div>
                            </div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $system_health['memory_usage'] }}%</span>
                        </div>
                    </div>

                    <!-- Disk Usage -->
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Disk') }}</span>
                        <div class="flex items-center space-x-2">
                            <div class="h-2 w-32 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-2 rounded-full {{ $system_health['disk_usage'] > 90 ? 'bg-red-500' : ($system_health['disk_usage'] > 75 ? 'bg-yellow-500' : 'bg-green-500') }}"
                                    style="width: {{ $system_health['disk_usage'] }}%"
                                ></div>
                            </div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $system_health['disk_usage'] }}%</span>
                        </div>
                    </div>

                    <!-- Load Average -->
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Load Avg') }}</span>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm font-medium {{ $system_health['load_average'] > 2 ? 'text-red-600 dark:text-red-400' : ($system_health['load_average'] > 1 ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400') }}">
                                {{ number_format($system_health['load_average'], 2) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
