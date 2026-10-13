<?php

use App\Enums\CacheDispatchResult;
use App\Services\ManagedCacheAccountGroupIdentity;
use App\Services\ManagedCacheCapacityService;
use App\Services\ManagedCacheCapacitySnapshot;

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

it('atomically reserves one managed prefetch per account group until it is released', function () {
    $service = app(ManagedCacheCapacityService::class);
    $group = app(ManagedCacheAccountGroupIdentity::class)->for('https://provider.example', 'account-42');
    $snapshot = new ManagedCacheCapacitySnapshot($group, limit: 3, live: 1, background: 0);

    $reservation = $service->reserve($snapshot);

    expect($reservation)->not->toBeNull()
        ->and($service->reserve($snapshot))->toBeNull();

    $service->release($reservation);

    expect($service->reserve($snapshot))->not->toBeNull();
});

it('keeps a file reservation available for the downloader until it releases the matching token', function () {
    $service = app(ManagedCacheCapacityService::class);
    $snapshot = new ManagedCacheCapacitySnapshot('opaque-group', limit: 3, live: 1, background: 0);
    $reservation = $service->reserve($snapshot);

    $service->attachToFile(42, $reservation);

    expect($service->reservationForFile(42))->toBe($reservation);

    $service->release($reservation);

    expect($service->reserve($snapshot))->not->toBeNull();
});

it('defers unbounded or incomplete snapshots without creating a reservation', function () {
    $service = app(ManagedCacheCapacityService::class);
    $group = app(ManagedCacheAccountGroupIdentity::class)->for('https://provider.example', 'account-42');

    expect($service->reserve(new ManagedCacheCapacitySnapshot($group, limit: null, live: 0, background: 0)))->toBeNull()
        ->and($service->reserve(new ManagedCacheCapacitySnapshot($group, limit: 3, live: null, background: 0)))->toBeNull();
});
