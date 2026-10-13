<?php

use App\Http\Controllers\Concerns\StreamLocalFile;

it('runs stream lifecycle callbacks around a successful response body', function () {
    $path = tempnam(sys_get_temp_dir(), 'stream-local-file-');
    file_put_contents($path, 'cache-bytes');
    $events = [];

    $response = StreamLocalFile::serve(
        $path,
        11,
        'video/mp4',
        'cache.mp4',
        null,
        onStart: function () use (&$events): void {
            $events[] = 'start';
        },
        onProgress: function () use (&$events): void {
            $events[] = 'progress';
        },
        onFinish: function () use (&$events): void {
            $events[] = 'finish';
        },
    );

    ob_start();
    ($response->getCallback())();
    ob_end_clean();
    unlink($path);

    expect($events)->toBe(['start', 'progress', 'finish']);
});
