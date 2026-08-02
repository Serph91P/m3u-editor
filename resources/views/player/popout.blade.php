<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $channelTitle }} - Player</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-black text-white">
    <main class="flex h-screen flex-col">
        <header class="flex items-center justify-between border-b border-white/10 bg-black/80 px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                @if ($channelLogo)
                    <img
                        src="{{ $channelLogo }}"
                        alt="{{ $channelTitle }}"
                        class="h-8 w-8 rounded object-cover"
                        onerror="this.style.display = 'none'"
                    />
                @endif
                <div class="min-w-0">
                    <h1 class="truncate text-sm font-semibold sm:text-base">{{ $channelTitle }}</h1>
                    <p class="text-xs text-white/70">{{ strtoupper($streamFormat) }} Stream</p>
                </div>
            </div>
        </header>

        <section class="group relative flex-1 overflow-hidden">
            <video
                id="popout-player"
                class="h-full w-full"
                controls
                autoplay
                preload="metadata"
                data-url="{{ $streamUrl }}"
                data-format="{{ $streamFormat }}"
                data-content-type="{{ $contentType }}"
                data-stream-id="{{ $streamId }}"
                data-playlist-id="{{ $playlistId }}"
                data-series-id="{{ $seriesId }}"
                data-season-number="{{ $seasonNumber }}"
            >
                <p class="p-4">Your browser does not support video playback.</p>
            </video>

            <div
                id="popout-player-loading"
                class="bg-opacity-50 absolute inset-0 flex items-center justify-center bg-black"
            >
                <div class="flex items-center gap-2 text-sm">
                    <svg
                        class="h-5 w-5 animate-spin"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span>Loading stream...</span>
                </div>
            </div>

            <div
                id="popout-player-error"
                class="bg-opacity-75 absolute inset-0 hidden items-center justify-center bg-black"
            >
                <div class="p-4 text-center">
                    <h2 class="text-lg font-semibold">Playback Error</h2>
                    <p id="popout-player-error-message" class="mt-2 text-sm text-white/75">
                        Unable to load the stream.
                    </p>
                    <button
                        type="button"
                        onclick="retryStream('popout-player')"
                        class="mt-4 rounded bg-blue-600 px-4 py-2 text-sm font-medium hover:bg-blue-500"
                    >
                        Retry
                    </button>
                </div>
            </div>

            <div
                id="popout-player-details-overlay"
                class="absolute top-2 left-2 z-10 hidden max-w-xs rounded bg-black/90 p-3 text-xs text-white"
            >
                <div class="mb-2 flex items-center justify-between">
                    <span class="font-medium">Stream Details</span>
                    <button
                        type="button"
                        onclick="toggleStreamDetails('popout-player')"
                        class="text-white/70 hover:text-white"
                    >
                        <x-heroicon-o-x-mark class="h-3 w-3" />
                    </button>
                </div>
                <div id="popout-player-details" class="space-y-1">
                    <div class="text-white/60">Loading stream details...</div>
                </div>
            </div>

            <div class="absolute top-2 left-2 flex gap-1 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                <button
                    type="button"
                    onclick="toggleStreamDetails('popout-player')"
                    class="rounded bg-black/75 px-2 py-1 text-xs text-white transition-colors hover:bg-black/90"
                    title="Toggle Stream Details"
                >
                    <x-heroicon-o-information-circle class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    id="popout-pip-btn"
                    onclick="togglePopoutPiP()"
                    class="rounded bg-black/75 px-2 py-1 text-xs text-white transition-colors hover:bg-black/90"
                    title="Picture-in-Picture"
                    style="display: none"
                >
                    <svg
                        class="h-4 w-4"
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
            </div>

            <!-- Resume Prompt (VOD / Episode) -->
            <div
                id="popout-player-resume"
                class="absolute right-0 bottom-14 left-0 z-20 flex hidden justify-center px-4"
            >
                <div class="flex max-w-sm items-center gap-3 rounded-lg bg-gray-900/95 px-4 py-2 text-sm text-white shadow-xl">
                    <x-heroicon-o-clock class="h-4 w-4 flex-shrink-0 text-blue-400" />
                    <span id="popout-player-resume-time" class="flex-1">Resume from 0:00</span>
                    <button
                        type="button"
                        onclick="document.getElementById('popout-player')._streamPlayer?.resumeFromSaved()"
                        class="flex-shrink-0 rounded bg-blue-600 px-3 py-1 text-xs transition-colors hover:bg-blue-700"
                    >
                        Resume
                    </button>
                    <button
                        type="button"
                        onclick="document.getElementById('popout-player')._streamPlayer?.startOver()"
                        class="flex-shrink-0 text-gray-400 transition-colors hover:text-white"
                        title="Start from beginning"
                    >
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!window.streamPlayer) {
                return;
            }

            const videoElement = document.getElementById('popout-player');
            if (!videoElement) {
                return;
            }

            const streamUrl = videoElement.dataset.url ?? '';
            const streamFormat = videoElement.dataset.format ?? 'ts';

            // Inherit the client_id from the floating player if this popout was
            // opened via "open in new tab", so the proxy sees an uninterrupted
            // connection. Fall back to a fresh id for direct popout loads.
            const urlParams = new URLSearchParams(window.location.search);
            const popoutClientId = urlParams.get('client_id') ?? 'popout-' + Math.random().toString(36).substring(2, 9);
            const clientIdSep = streamUrl.includes('?') ? '&' : '?';
            const streamUrlWithClientId = streamUrl + clientIdSep + 'client_id=' + encodeURIComponent(popoutClientId);

            const player = window.streamPlayer();
            player.initPlayer(streamUrlWithClientId, streamFormat, 'popout-player');

            // Show PiP button if supported
            if (document.pictureInPictureEnabled) {
                const pipBtn = document.getElementById('popout-pip-btn');
                if (pipBtn) pipBtn.style.display = '';
            }

            window.togglePopoutPiP = function () {
                if (document.pictureInPictureElement === videoElement) {
                    document.exitPictureInPicture().catch(() => {});
                } else if (document.pictureInPictureEnabled) {
                    videoElement.requestPictureInPicture().catch(() => {});
                }
            };

            // Unified cleanup for page unload (beforeunload + pagehide for
            // mobile Safari). Runs once to avoid duplicate proxy stop
            // requests and double media teardown.
            const streamType = videoElement.dataset.contentType === 'episode' ? 'episode' : 'channel';
            let popoutFinalized = false;
            function finalizePopoutSession() {
                if (popoutFinalized) return;
                popoutFinalized = true;

                if (window.notifyProxyStreamStop) {
                    window.notifyProxyStreamStop(videoElement.dataset.streamId || '', streamType, popoutClientId);
                }
                if (typeof player.cleanup === 'function') {
                    player.cleanup();
                }
            }

            window.addEventListener('beforeunload', finalizePopoutSession);
            window.addEventListener('pagehide', finalizePopoutSession);

            document.addEventListener('visibilitychange', () => {
                const isLive = videoElement.dataset.contentType === 'live';
                if (isLive) {
                    return;
                }
                if (document.visibilityState === 'hidden') {
                    videoElement.pause();
                } else if (document.visibilityState === 'visible') {
                    videoElement.play().catch(() => {});
                }
            });
        });
    </script>
</body>
</html>
