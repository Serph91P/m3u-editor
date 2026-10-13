<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\CachedContentActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    config(['cache.default' => 'array']);
});

it('keeps independent playback sessions active and stops only the named lease', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $service = app(CachedContentActivityService::class);
    $target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:tmdb:550',
        'variant_key' => '1080p',
    ];

    $first = $service->acquirePlayback($user, $integration, 'session-a', 1, $target);
    $second = $service->acquirePlayback($user, $integration, 'session-b', 1, $target);

    expect($service->hasActivePlayback($target))->toBeTrue();

    $service->stopPlayback($integration, $first->lease_id, 2);

    expect($service->hasActivePlayback($target))->toBeTrue();

    $service->stopPlayback($integration, $second->lease_id, 2);

    expect($service->hasActivePlayback($target))->toBeFalse();
});
