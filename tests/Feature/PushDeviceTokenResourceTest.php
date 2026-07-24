<?php

use App\Filament\Resources\PushDeviceTokens\Pages\ListPushDeviceTokens;
use App\Filament\Resources\PushDeviceTokens\PushDeviceTokenResource;
use App\Models\Playlist;
use App\Models\PushDeviceToken;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->playlist = Playlist::factory()->for($this->admin)->create();
    $this->actingAs($this->admin);
});

it('is only accessible to admins', function () {
    expect(PushDeviceTokenResource::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create());
    expect(PushDeviceTokenResource::canAccess())->toBeFalse();
});

it('blocks non-admin users from reaching the list page', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListPushDeviceTokens::class)
        ->assertForbidden();
});

it('lists devices across every user\'s playlists, not just the admin\'s own', function () {
    $ownDevice = PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create();

    $otherPlaylist = Playlist::factory()->for(User::factory()->create())->create();
    $otherDevice = PushDeviceToken::factory()->for($otherPlaylist, 'notifiable')->create();

    Livewire::test(ListPushDeviceTokens::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$ownDevice, $otherDevice]);
});

it('can revoke (delete) a device from the table', function () {
    $device = PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create();

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->callAction(TestAction::make('delete')->table($device));

    expect(PushDeviceToken::find($device->id))->toBeNull();
});

it('filters to stale devices past the prune window', function () {
    config(['services.push_relay.stale_days' => 60]);

    $stale = PushDeviceToken::factory()->for($this->playlist, 'notifiable')
        ->create(['last_seen_at' => now()->subDays(61)]);
    $fresh = PushDeviceToken::factory()->for($this->playlist, 'notifiable')
        ->create(['last_seen_at' => now()->subDays(1)]);

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->filterTable('stale')
        ->assertCanSeeTableRecords([$stale])
        ->assertCanNotSeeTableRecords([$fresh]);
});
