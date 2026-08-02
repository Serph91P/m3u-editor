<!-- Floating Stream Players Container -->
@php
    $maxPlayers = app(\App\Settings\GeneralSettings::class)->max_concurrent_floating_players ?? 0;
@endphp
<div
    data-max-players="{{ $maxPlayers }}"
    x-data="
        (() => {
            // Create a unique instance ID to avoid conflicts
            const instanceId = 'floating-streams-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);

            // Only create a new global manager if none exists, or if it's from a different instance
            if (!window._globalMultiStreamManager || window._globalMultiStreamManager._instanceId !== instanceId) {
                // Clean up any existing instance
                if (
                    window._globalMultiStreamManager &&
                    typeof window._globalMultiStreamManager.cleanupAllStreams === 'function'
                ) {
                    try {
                        window._globalMultiStreamManager.cleanupAllStreams();
                    } catch (e) {
                        console.warn('Error during cleanup:', e);
                    }
                }

                // Create new instance with unique ID
                const manager = multiStreamManager();
                manager._instanceId = instanceId;
                window._globalMultiStreamManager = manager;
            }

            return window._globalMultiStreamManager;
        })()
    "
    x-init="init()"
    x-on:alpine:destroyed="
        if (typeof cleanupAllStreams === 'function') {
            cleanupAllStreams();
        }
    "
    class="pointer-events-none fixed inset-0 z-[9999]"
>
    <!-- Multiple Floating Players -->
    <template x-for="player in players" :key="player.id">
        <div
            :style="getPlayerStyle(player)"
            :class="{ 'scale-75 opacity-80': player.isMinimized, 'scale-100 opacity-100': ! player.isMinimized }"
            class="pointer-events-auto overflow-hidden rounded-lg border border-gray-200 bg-white shadow-2xl transition-all duration-200 ease-in-out hover:-translate-y-0.5 hover:shadow-slate-500/25 dark:border-gray-700 dark:bg-gray-800"
            @mousedown="bringToFront(player.id)"
        >
            <!-- Player Header/Title Bar -->
            <div
                class="flex cursor-move items-center justify-between border-b border-gray-200 bg-gray-50 p-2 transition-colors select-none hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-700 dark:hover:bg-gray-600"
                @mousedown="startDrag(player.id, $event)"
                @touchstart="startDrag(player.id, $event)"
            >
                <div class="flex min-w-0 flex-1 items-center space-x-2">
                    <img
                        x-show="player.logo"
                        :src="player.logo"
                        :alt="player.title"
                        class="h-5 w-5 flex-shrink-0 rounded object-cover"
                        onerror="this.style.display = 'none'"
                    />
                    <span
                        class="truncate text-sm font-medium text-gray-900 dark:text-gray-100"
                        x-text="player.display_title || player.title"
                    ></span>
                </div>

                <div class="flex flex-shrink-0 items-center space-x-1" @mousedown.stop @touchstart.stop>
                    <!-- Open in New Tab Button -->
                    <button
                        @click.stop="openInNewTab(player, '{{ route('player.popout') }}')"
                        class="rounded p-1 text-gray-400 transition-colors hover:bg-blue-50 hover:text-blue-600 focus:outline-none dark:hover:bg-blue-900/20 dark:hover:text-blue-400"
                        title="Open in new tab"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                    </button>

                    <!-- Picture-in-Picture Button -->
                    <button
                        x-show="document.pictureInPictureEnabled"
                        @click.stop="togglePiP(player.id)"
                        class="rounded p-1 text-gray-400 transition-colors hover:bg-purple-50 hover:text-purple-600 focus:outline-none dark:hover:bg-purple-900/20 dark:hover:text-purple-400"
                        title="Picture-in-Picture"
                    >
                        <svg
                            class="h-3 w-3"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <rect x="2" y="3" width="20" height="14" rx="2" />
                            <rect x="12" y="9" width="8" height="6" rx="1" fill="currentColor" />
                        </svg>
                    </button>

                    <!-- Minimize Button -->
                    <button
                        @click.stop="toggleMinimize(player.id)"
                        class="rounded p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 focus:outline-none dark:hover:bg-gray-700 dark:hover:text-gray-300"
                        title="Minimize"
                    >
                        <x-heroicon-o-minus class="h-3 w-3" />
                    </button>

                    <!-- Close Button -->
                    <button
                        @click.stop="closeStream(player.id)"
                        class="rounded p-1 text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600 focus:outline-none dark:hover:bg-red-900/20 dark:hover:text-red-400"
                        title="Close"
                    >
                        <x-heroicon-o-x-mark class="h-3 w-3" />
                    </button>
                </div>
            </div>

            <!-- PiP Indicator (shown when video is in Picture-in-Picture) -->
            <div
                x-show="player.isPiP && ! player.isMinimized"
                class="flex items-center justify-center gap-2 bg-gray-900 px-4 py-3 text-xs text-gray-400"
            >
                <svg
                    class="h-4 w-4 text-purple-400"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <rect x="2" y="3" width="20" height="14" rx="2" />
                    <rect x="12" y="9" width="8" height="6" rx="1" fill="currentColor" />
                </svg>
                <span>Playing in Picture-in-Picture</span>
            </div>

            <!-- Video Player Area -->
            <div
                x-show="! player.isMinimized && ! player.isPiP"
                class="group relative bg-black"
                :style="getVideoStyle(player)"
            >
                <!-- Video Element -->
                <video
                    :id="player.id + '-video'"
                    class="h-full w-full"
                    controls
                    autoplay
                    preload="metadata"
                    x-data="{ playerInstance: null }"
                    :data-stream-url="player.url"
                    :data-stream-format="player.format"
                    :data-player-id="player.id"
                    :data-content-type="player.content_type || ''"
                    :data-stream-id="player.stream_id || ''"
                    :data-playlist-id="player.playlist_id || ''"
                    :data-series-id="player.series_id || ''"
                    :data-season-number="player.season_number || ''"
                    :data-edl-url="player.edl_url || ''"
                    :data-title="player.title || ''"
                    :data-aio-item-id="player.aio_item_id || ''"
                    :data-aio-integration-id="player.aio_integration_id || ''"
                    :data-episode-number="player.episode_number || ''"
                    :data-episode-title="player.episode_title || ''"
                    :data-thumbnail-url="player.thumbnail_url || ''"
                    :data-backdrop-url="player.backdrop_url || ''"
                    :data-rating="player.rating || ''"
                    :data-year="player.year || ''"
                    :data-plot="player.plot || ''"
                    x-init="
                        if (window.streamPlayer && $el.dataset.streamUrl && $el.dataset.streamUrl !== '') {
                            playerInstance = window.streamPlayer();
                            const sep = $el.dataset.streamUrl.includes('?') ? '&' : '?';
                            const urlWithClientId =
                                $el.dataset.streamUrl + sep + 'client_id=' + encodeURIComponent($el.dataset.playerId);
                            playerInstance.initPlayer(urlWithClientId, $el.dataset.streamFormat, $el.id);
                        }
                    "
                >
                    <p class="p-4 text-white">Your browser does not support video playback.</p>
                </video>

                <!-- Loading Overlay -->
                <div
                    :id="player.id + '-video-loading'"
                    class="bg-opacity-50 absolute inset-0 flex items-center justify-center bg-black"
                >
                    <div class="flex items-center space-x-2 text-white">
                        <svg
                            class="h-4 w-4 animate-spin"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            ></path>
                        </svg>
                        <span class="text-xs">Loading...</span>
                    </div>
                </div>

                <!-- Error Overlay -->
                <div
                    :id="player.id + '-video-error'"
                    class="bg-opacity-75 absolute inset-0 flex hidden items-center justify-center bg-black"
                >
                    <div class="p-4 text-center text-white">
                        <x-heroicon-o-exclamation-triangle class="mx-auto mb-2 h-8 w-8 text-red-400" />
                        <p class="text-sm">Failed to load stream</p>
                        <button
                            class="mt-2 rounded bg-red-600 px-3 py-1 text-xs transition-colors hover:bg-red-700"
                            @click="
                                const videoEl = document.getElementById(player.id + '-video');
                                if (videoEl && videoEl._streamPlayer) {
                                    const sep = player.url.includes('?') ? '&' : '?';
                                    const urlWithClientId =
                                        player.url + sep + 'client_id=' + encodeURIComponent(player.id);
                                    videoEl._streamPlayer.initPlayer(
                                        urlWithClientId,
                                        player.format,
                                        player.id + '-video',
                                    );
                                }
                            "
                        >
                            Retry
                        </button>
                    </div>
                </div>

                <!-- Stream Details Toggle -->
                <div class="absolute top-2 left-2 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                    <button
                        type="button"
                        @click="
                            const overlay = document.getElementById(player.id + '-video-details-overlay');
                            if (overlay) {
                                overlay.classList.toggle('hidden');
                            }
                        "
                        class="bg-opacity-75 hover:bg-opacity-90 rounded bg-black px-2 py-1 text-xs text-white transition-colors"
                        title="Toggle Stream Details"
                    >
                        <x-heroicon-o-information-circle class="h-4 w-4" />
                    </button>
                </div>

                <!-- Stream Details Overlay -->
                <div
                    :id="player.id + '-video-details-overlay'"
                    class="bg-opacity-90 absolute top-2 left-2 z-10 hidden max-w-xs rounded bg-black p-3 text-xs text-white"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <span class="font-medium">Stream Details</span>
                        <button
                            type="button"
                            @click="
                                const overlay = document.getElementById(player.id + '-video-details-overlay');
                                if (overlay) {
                                    overlay.classList.add('hidden');
                                }
                            "
                            class="text-gray-300 hover:text-white"
                        >
                            <x-heroicon-o-x-mark class="h-3 w-3" />
                        </button>
                    </div>
                    <div :id="player.id + '-video-details'" class="space-y-1">
                        <div class="text-gray-400">Loading stream details...</div>
                    </div>
                </div>

                <!-- Resume Prompt (VOD / Episode) -->
                <div
                    :id="player.id + '-video-resume'"
                    class="absolute right-0 bottom-10 left-0 z-20 flex hidden justify-center px-3"
                >
                    <div class="flex max-w-xs items-center gap-3 rounded-lg bg-gray-900/95 px-3 py-2 text-xs text-white shadow-xl">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4 flex-shrink-0 text-blue-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="1.5"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                            />
                        </svg>
                        <span :id="player.id + '-video-resume-time'" class="flex-1 truncate">Resume from 0:00</span>
                        <button
                            class="flex-shrink-0 rounded bg-blue-600 px-2 py-1 transition-colors hover:bg-blue-700"
                            @click.stop="
                                const v = document.getElementById(player.id + '-video');
                                if (v && v._streamPlayer) v._streamPlayer.resumeFromSaved();
                            "
                        >
                            Resume
                        </button>
                        <button
                            class="flex-shrink-0 text-gray-400 transition-colors hover:text-white"
                            @click.stop="
                                const v = document.getElementById(player.id + '-video');
                                if (v && v._streamPlayer) v._streamPlayer.startOver();
                            "
                            title="Start from beginning"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.5"
                                stroke="currentColor"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Skip Ad Prompt (DVR recordings with comskip EDL) -->
                <div
                    :id="player.id + '-video-skipad'"
                    class="absolute right-0 bottom-10 left-0 z-20 flex hidden justify-center px-3"
                >
                    <div class="flex max-w-xs items-center gap-3 rounded-lg bg-gray-900/95 px-3 py-2 text-xs text-white shadow-xl">
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4 flex-shrink-0 text-yellow-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="1.5"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="m3.75 7.5 16.5-4.125M12 6.75c-2.708 0-5.363.224-7.948.655C2.996 7.685 2.25 8.662 2.25 9.75v9a1.5 1.5 0 0 0 1.5 1.5h16.5a1.5 1.5 0 0 0 1.5-1.5v-9c0-1.088-.746-2.065-1.802-2.345A48.507 48.507 0 0 0 12 6.75Z"
                            />
                        </svg>
                        <span class="flex-1 truncate">Commercial detected</span>
                        <button
                            class="flex-shrink-0 rounded bg-yellow-500 px-2 py-1 font-medium text-gray-900 transition-colors hover:bg-yellow-400"
                            @click.stop="
                                const v = document.getElementById(player.id + '-video');
                                if (v && v._streamPlayer) v._streamPlayer.skipCommercial();
                            "
                        >
                            Skip Ad
                        </button>
                    </div>
                </div>

                <!-- Resize Handle -->
                <div
                    class="group absolute right-0 bottom-0 h-4 w-4 cursor-se-resize opacity-50 transition-opacity hover:opacity-100"
                    @mousedown.stop="startResize(player.id, $event)"
                    @touchstart.stop="startResize(player.id, $event)"
                    title="Resize"
                >
                    <!-- Visual resize indicator with lines -->
                    <div class="absolute right-1 bottom-1 space-y-0.5">
                        <div class="flex space-x-0.5">
                            <div class="h-0.5 w-0.5 bg-gray-400 transition-colors group-hover:bg-indigo-500"></div>
                            <div class="h-0.5 w-0.5 bg-gray-400 transition-colors group-hover:bg-indigo-500"></div>
                        </div>
                        <div class="flex space-x-0.5">
                            <div class="h-0.5 w-0.5 bg-gray-400 transition-colors group-hover:bg-indigo-500"></div>
                            <div class="h-0.5 w-0.5 bg-gray-400 transition-colors group-hover:bg-indigo-500"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
