<div class="w-full">
    @php($rows = $getState())
    @if (is_array($rows))
        <div class="max-h-48 w-full space-y-1 overflow-y-auto">
            @foreach ($rows as $key => $value)
                <div class="flex w-full flex-col gap-1 rounded bg-gray-100 px-2 py-1 text-xs sm:flex-row sm:items-center sm:justify-between sm:gap-x-2 dark:bg-gray-800">
                    <span class="flex-shrink-0 font-medium text-gray-700 dark:text-gray-300">{{ $key }}:</span>
                    @if (is_array($value))
                        <span class="font-mono break-all text-gray-900 sm:text-right dark:text-gray-100">
                            {{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
                        </span>
                    @else
                        <span class="font-mono break-all text-gray-900 sm:text-right dark:text-gray-100">{{ $value }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
