<?php

use App\Filament\Resources\PushDeviceTokens\Pages\ListPushDeviceTokens;
use App\Filament\Resources\PushDeviceTokens\PushDeviceTokenResource;
use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides the devices/pairing page from non-admins', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(PushDeviceTokenResource::canAccess())->toBeFalse();
});

it('allows admins to access the devices/pairing page', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(PushDeviceTokenResource::canAccess())->toBeTrue();
});

it('approves a pending code and assigns the chosen credential', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
        ->fillForm([
            'user_code' => $deviceAuth->user_code,
            'playlist_auth_id' => $playlistAuth->id,
        ], 'content')
        ->call('approve');

    $this->assertDatabaseHas('device_authorizations', [
        'id' => $deviceAuth->id,
        'status' => 'approved',
        'playlist_auth_id' => $playlistAuth->id,
        'approved_by_user_id' => $admin->id,
    ]);
});

it('shows a generic error for an unknown or expired code', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
        ->fillForm([
            'user_code' => 'ZZZZ-ZZZZ',
            'playlist_auth_id' => $playlistAuth->id,
        ], 'content')
        ->call('approve')
        ->assertNotified();
});

it('only offers the authenticated admin\'s own playlist auths in the picker', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $ownAuth = PlaylistAuth::factory()->for($admin)->create();

    $otherUser = User::factory()->create();
    $otherAuth = PlaylistAuth::factory()->for($otherUser)->create();

    $options = PlaylistAuth::where('user_id', auth()->id())->pluck('name', 'id')->all();

    expect($options)->toHaveKey($ownAuth->id);
    expect($options)->not->toHaveKey($otherAuth->id);
});

it('rejects approval when the posted playlist_auth_id does not belong to the admin', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $otherUser = User::factory()->create();
    $otherAuth = PlaylistAuth::factory()->for($otherUser)->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
        ->fillForm([
            'user_code' => $deviceAuth->user_code,
            'playlist_auth_id' => $otherAuth->id,
        ], 'content')
        ->call('approve');

    $this->assertDatabaseHas('device_authorizations', [
        'id' => $deviceAuth->id,
        'status' => 'pending',
    ]);
});
