<x-filament-panels::page>
    {{-- Filter tabs --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @php
            $tabs = [
                'all' => ['label' => 'All Releases', 'color' => 'gray'],
                'master' => ['label' => 'Latest', 'color' => 'primary'],
                'dev' => ['label' => 'Dev', 'color' => 'warning'],
                'experimental' => ['label' => 'Experimental', 'color' => 'danger'],
            ];
        @endphp

        @foreach ($tabs as $key => $tab)
            <x-filament::button
                wire:click="setFilter('{{ $key }}')"
                color="{{ $tab['color'] }}"
                icon="{{ $this->filter === $key ? 'heroicon-s-check-circle' : '' }}"
                size="sm"
                class="flex items-center gap-1"
            >
                {{ $tab['label'] }}
                <x-filament::badge size="sm" color="{{ $tab['color'] }}"> {{ $counts[$key] ?? 0 }} </x-filament::badge>
            </x-filament::button>
        @endforeach
    </div>

    {{-- Release table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        @if (! empty($releases))
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($releases as $release)
                    <div
                        wire:key="release-{{ $filter }}-{{ $release['tag'] }}"
                        x-data="{ open: {{ $release['is_current'] ? 'true' : 'false' }} }"
                        class="group"
                    >
                        {{-- Row header --}}
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/60"
                        >
                            {{-- Chevron --}}
                            <x-heroicon-m-chevron-right
                                class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-200"
                                x-bind:class="{ 'rotate-90': open }"
                            />

                            {{-- Version / name --}}
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $release['name'] ?: $release['tag'] }}
                            </span>

                            {{-- Current badge --}}
                            @if ($release['is_current'])
                                <x-filament::badge color="success" size="sm" icon="heroicon-s-check-circle">
                                    Current
                                </x-filament::badge>
                            @endif

                            {{-- Type badge --}}
                            @php
                                $typeColor = match ($release['type']) {
                                    'dev' => 'warning',
                                    'experimental' => 'danger',
                                    default => 'primary',
                                };
                                $typeLabel = match ($release['type']) {
                                    'dev' => 'Dev',
                                    'experimental' => 'Experimental',
                                    default => 'Latest',
                                };
                            @endphp
                            <x-filament::badge :color="$typeColor" size="sm"> {{ $typeLabel }} </x-filament::badge>

                            {{-- Spacer --}}
                            <span class="flex-1"></span>

                            {{-- Date --}}
                            @if (! empty($release['published_at']))
                                <span class="flex-shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                    {{ \Illuminate\Support\Carbon::parse($release['published_at'])->format('M j, Y') }}
                                    <span class="hidden text-gray-400 sm:inline dark:text-gray-500">
                                        &middot; {{ \Illuminate\Support\Carbon::parse($release['published_at'])->diffForHumans() }}
                                    </span>
                                </span>
                            @endif

                            {{-- GitHub link --}}
                            @if (! empty($release['url']))
                                <a
                                    href="{{ $release['url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    x-on:click.stop
                                    class="hover:text-primary-600 dark:hover:text-primary-400 ml-2 flex-shrink-0 text-gray-400 transition-colors"
                                    title="View on GitHub"
                                >
                                    <x-heroicon-s-arrow-top-right-on-square class="h-4 w-4" />
                                </a>
                            @endif
                        </button>

                        {{-- Expandable release notes --}}
                        <div x-show="open" x-collapse>
                            @if (! empty($release['body']))
                                <div class="px-11 pt-1 pb-4">
                                    <div class="prose prose-sm dark:prose-invert release-body-content max-w-none font-mono text-sm">
                                        {!! $this->formatMarkdown($release['body']) !!}
                                    </div>
                                </div>
                            @else
                                <div class="px-11 pt-1 pb-4 text-sm text-gray-400 italic dark:text-gray-500">
                                    No release notes available.
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <x-heroicon-o-inbox class="mb-3 h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No releases found</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    @if ($filter !== 'all')
                        No {{ $filter }} releases cached yet.
                    @else
                        No release information is currently available.
                    @endif
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
