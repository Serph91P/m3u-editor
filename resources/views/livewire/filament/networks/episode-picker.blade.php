<div class="space-y-4">
    {{-- Filters --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
        <div class="w-72">
            <label for="series" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Series</label>
            <select
                id="series"
                wire:model.live="seriesId"
                class="focus:border-primary-500 focus:ring-primary-500 dark:[&>option]:bg-gray-800 dark:[&>option]:text-white mt-1 block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm transition duration-75 focus:ring-1 focus:ring-inset sm:text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
            >
                <option value="">-- select series --</option>
                @foreach ($seriesOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex-1">
            <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Search</label>
            <input
                id="search"
                wire:model.live.debounce.300ms="search"
                type="search"
                class="focus:border-primary-500 focus:ring-primary-500 mt-1 block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm transition duration-75 placeholder:text-gray-400 focus:ring-1 focus:ring-inset sm:text-sm dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                placeholder="Title, season, episode number"
            />
        </div>

        <div class="w-32">
            <label for="perPage" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Per page</label>
            <select
                id="perPage"
                wire:model.live="perPage"
                class="focus:border-primary-500 focus:ring-primary-500 dark:[&>option]:bg-gray-800 dark:[&>option]:text-white mt-1 block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm transition duration-75 focus:ring-1 focus:ring-inset sm:text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white"
            >
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
            </select>
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="w-12 px-4 py-3">
                        <input
                            type="checkbox"
                            wire:click="toggleSelectAllOnPage"
                            class="text-primary-600 focus:ring-primary-500 dark:checked:bg-primary-500 rounded border-gray-300 shadow-sm dark:border-white/10 dark:bg-white/5"
                            aria-label="Select all on page"
                        />
                    </th>
                    <th class="w-24 px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                        Episode
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                        Title
                    </th>
                    <th class="w-28 px-4 py-3 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                        Duration
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-white/5 dark:bg-gray-900">
                @forelse ($episodes as $ep)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-3">
                            <input
                                type="checkbox"
                                wire:model.live="selected"
                                value="{{ $ep->id }}"
                                class="text-primary-600 focus:ring-primary-500 dark:checked:bg-primary-500 rounded border-gray-300 shadow-sm dark:border-white/10 dark:bg-white/5"
                            />
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            S{{ $ep->season }}E{{ $ep->episode_num }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-950 dark:text-white">{{ $ep->title }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                            @if ($ep->duration)
                                {{ gmdate('i:s', $ep->duration) }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            @if ($seriesId)
                                No episodes found for the selected series.
                            @else
                                Select a series to view episodes.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Footer --}}
    <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Showing {{ $episodes->firstItem() ?? 0 }}–{{ $episodes->lastItem() ?? 0 }} of {{ $episodes->total() }}
        </p>

        <div class="flex items-center gap-4">
            {{ $episodes->links() }}

            <button
                wire:click="addSelected"
                type="button"
                class="bg-primary-600 hover:bg-primary-500 focus:ring-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition duration-75 focus:ring-2 focus:ring-offset-2 focus:outline-none dark:focus:ring-offset-gray-900"
            >
                <x-heroicon-m-plus class="h-4 w-4" />
                Add Selected ({{ count($selected) }})
            </button>
        </div>
    </div>
</div>
