<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a device_code/user_code pair with expiry and verification uri', function () {
    $response = $this->postJson('/api/device/code');

    $response->assertOk()->assertJsonStructure([
        'device_code',
        'user_code',
        'verification_uri',
        'interval',
        'expires_in',
    ]);

    $body = $response->json();

    expect($body['user_code'])->toMatch('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/');
    expect(strlen($body['device_code']))->toBe(64);
    expect($body['verification_uri'])->toContain('code='.$body['user_code']);
    expect($body['interval'])->toBe(5);
    expect($body['expires_in'])->toBeGreaterThan(0);

    $this->assertDatabaseHas('device_authorizations', [
        'device_code' => $body['device_code'],
        'user_code' => $body['user_code'],
        'status' => 'pending',
    ]);
});

it('applies throttle middleware to the device code request route', function () {
    $route = app('router')->getRoutes()->getByName('device.code');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('throttle:20,1');
});

it('applies throttle middleware to the device token poll route', function () {
    $route = app('router')->getRoutes()->getByName('device.token');

    expect($route)->not->toBeNull();
    expect($route->middleware())->toContain('throttle:60,1');
});
