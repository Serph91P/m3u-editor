<div>
    @php($model = $getRecord()->model)
    @php($urls = \App\Facades\PlaylistFacade::getUrls($model))
    @php($m3uUrl = $urls['m3u'])
    @php($hdhrUrl = $urls['hdhr'])
    <div class="flex flex-col gap-4 px-3 py-2">
        <div class="flex flex-col text-sm">
            <p class="font-bold">M3U URL</p>
            <a
                href="{{ $m3uUrl }}"
                target="_blank"
                class="text-primary-500 hover:text-primary-700 dark:hover:text-primary-300 flex items-center gap-1 underline"
            >
                {{ $m3uUrl }}
            </a>
        </div>
        <div class="flex flex-col text-sm">
            <p class="font-bold">HDHR URL</p>
            <a
                href="{{ $hdhrUrl }}"
                target="_blank"
                class="text-primary-500 hover:text-primary-700 dark:hover:text-primary-300 flex items-center gap-1 underline"
            >
                {{ $hdhrUrl }}
            </a>
        </div>
    </div>
</div>
