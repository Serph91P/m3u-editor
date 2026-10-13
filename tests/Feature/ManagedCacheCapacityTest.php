<?php

use App\Enums\CacheDispatchResult;
use App\Services\ManagedCacheCapacityService;

it('fails closed when managed cache capacity is unknown', function () {
    expect(app(ManagedCacheCapacityService::class)->availability(null, null, null))
        ->toBe(CacheDispatchResult::Deferred);
});

it('reserves one provider slot for live playback and one for managed background work', function () {
    $service = app(ManagedCacheCapacityService::class);

    expect($service->availability(3, 1, 0))->toBe(CacheDispatchResult::Queued)
        ->and($service->availability(3, 2, 0))->toBe(CacheDispatchResult::Deferred)
        ->and($service->availability(3, 1, 1))->toBe(CacheDispatchResult::Deferred)
        ->and($service->availability(3, 3, 0))->toBe(CacheDispatchResult::Deferred);
});
