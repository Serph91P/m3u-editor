<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Fresh-process probe: do not use tests/bootstrap.php, which clears caches.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->instance('request', Request::create('http://localhost'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
Http::preventStrayRequests();

$send = function (string $path) use ($kernel) {
    $request = Request::create($path, 'POST', server: [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_TOKEN' => 'cached-callback-test-token',
        'REMOTE_ADDR' => '127.0.0.1',
    ]);

    return $kernel->handle($request);
};

$first = $send('/api/m3u-proxy/webhooks');
$limit = (int) $first->headers->get('X-RateLimit-Limit');
if ($limit < 1 || $limit > 120) {
    throw new RuntimeException('Unexpected test allowance');
}
for ($i = 1; $i < $limit; $i++) {
    if ($send('/api/m3u-proxy/webhooks')->getStatusCode() !== 200) {
        throw new RuntimeException('Callback throttled before configured allowance');
    }
}

echo json_encode([
    'config_cached' => $app->configurationIsCached(),
    'routes_cached' => $app->routesAreCached(),
    'enabled' => config('proxy.isolated_callback_rate_limits'),
    'webhook_limit' => $first->headers->get('X-RateLimit-Limit'),
    'statuses' => [
        $first->getStatusCode(),
        $send('/api/m3u-proxy/webhooks')->getStatusCode(),
        $send('/api/m3u-proxy/failover-resolver')->getStatusCode(),
    ],
], JSON_THROW_ON_ERROR);
