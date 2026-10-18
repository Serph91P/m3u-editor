<?php

use App\Filament\Resources\DvrRecordingRules\Pages\EditDvrRecordingRule;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Support\DvrPaddingFields;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('combines amount, unit and direction into signed seconds', function (mixed $amount, ?string $unit, ?string $direction, ?int $expected) {
    expect(DvrPaddingFields::toSeconds($amount, $unit, $direction))->toBe($expected);
})->with([
    'seconds, extend' => [30, 'sec', 'extend', 30],
    'minutes, extend' => [5, 'min', 'extend', 300],
    'seconds, trim' => [45, 'sec', 'trim', -45],
    'minutes, trim' => [90, 'min', 'trim', -5400],
    'zero stays zero' => [0, 'min', 'trim', 0],
    'numeric string' => ['2', 'min', 'extend', 120],
    'blank means inherit' => [null, 'sec', 'extend', null],
    'empty string means inherit' => ['', 'min', 'trim', null],
]);

it('splits signed seconds into amount, unit and direction', function (mixed $seconds, array $expected) {
    expect(DvrPaddingFields::fromSeconds($seconds))->toBe($expected);
})->with([
    'null inherits' => [null, ['amount' => null, 'unit' => 'sec', 'direction' => 'extend']],
    'zero is explicit no padding' => [0, ['amount' => 0, 'unit' => 'sec', 'direction' => 'extend']],
    'seconds' => [45, ['amount' => 45, 'unit' => 'sec', 'direction' => 'extend']],
    'whole minutes' => [300, ['amount' => 5, 'unit' => 'min', 'direction' => 'extend']],
    'negative minutes' => [-3600, ['amount' => 60, 'unit' => 'min', 'direction' => 'trim']],
    'negative seconds' => [-90 - 1, ['amount' => 91, 'unit' => 'sec', 'direction' => 'trim']],
]);

it('clamps padding to 24 hours either way', function (int $seconds, int $expected) {
    expect(DvrPaddingFields::clampSeconds($seconds))->toBe($expected);
})->with([
    'within range' => [-300, -300],
    'at the limit' => [86400, 86400],
    'over the limit' => [999999, 86400],
    'under the negative limit' => [-999999, -86400],
]);

it('formats signed padding for summaries', function () {
    expect(DvrPaddingFields::formatSeconds(30))->toBe('+30s')
        ->and(DvrPaddingFields::formatSeconds(-300))->toBe('-5m')
        ->and(DvrPaddingFields::formatSeconds(0))->toBe('+0s');
});

describe('rule edit form', function () {
    beforeEach(function () {
        $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
        $this->actingAs($this->user);

        $playlist = Playlist::factory()->for($this->user)->create();
        $this->dvrSetting = DvrSetting::factory()->enabled()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $playlist->id,
        ]);
    });

    it('hydrates stored padding into amount, unit and direction', function () {
        $rule = DvrRecordingRule::factory()
            ->series()
            ->for($this->dvrSetting, 'dvrSetting')
            ->for($this->user)
            ->create(['start_early_seconds' => -120, 'end_late_seconds' => 90]);

        Livewire::test(EditDvrRecordingRule::class, ['record' => $rule->getRouteKey()])
            ->assertFormSet([
                'start_early_seconds_amount' => 2,
                'start_early_seconds_unit' => 'min',
                'start_early_seconds_direction' => 'trim',
                'end_late_seconds_amount' => 90,
                'end_late_seconds_unit' => 'sec',
                'end_late_seconds_direction' => 'extend',
            ]);
    });

    it('saves minutes and direction as signed seconds', function () {
        $rule = DvrRecordingRule::factory()
            ->series()
            ->for($this->dvrSetting, 'dvrSetting')
            ->for($this->user)
            ->create(['start_early_seconds' => null, 'end_late_seconds' => null]);

        Livewire::test(EditDvrRecordingRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm([
                'start_early_seconds_amount' => 5,
                'start_early_seconds_unit' => 'min',
                'start_early_seconds_direction' => 'trim',
                'end_late_seconds_amount' => 10,
                'end_late_seconds_unit' => 'sec',
                'end_late_seconds_direction' => 'trim',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($rule->refresh())
            ->start_early_seconds->toBe(-300)
            ->end_late_seconds->toBe(-10);
    });

    it('keeps blank padding as null so the playlist default is inherited', function () {
        $rule = DvrRecordingRule::factory()
            ->series()
            ->for($this->dvrSetting, 'dvrSetting')
            ->for($this->user)
            ->create(['start_early_seconds' => 120, 'end_late_seconds' => 120]);

        Livewire::test(EditDvrRecordingRule::class, ['record' => $rule->getRouteKey()])
            ->fillForm([
                'start_early_seconds_amount' => null,
                'end_late_seconds_amount' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($rule->refresh())
            ->start_early_seconds->toBeNull()
            ->end_late_seconds->toBeNull();
    });

    it('rejects padding longer than 24 hours and accepts exactly 24 hours', function () {
        $rule = DvrRecordingRule::factory()
            ->series()
            ->for($this->dvrSetting, 'dvrSetting')
            ->for($this->user)
            ->create(['start_early_seconds' => null, 'end_late_seconds' => null]);

        Livewire::test(EditDvrRecordingRule::class, ['record' => $rule->getRouteKey()])
            ->set('data.start_early_seconds_amount', 1441)
            ->set('data.start_early_seconds_unit', 'min')
            ->call('save')
            ->assertHasFormErrors(['start_early_seconds_amount']);

        expect($rule->refresh()->start_early_seconds)->toBeNull();

        Livewire::test(EditDvrRecordingRule::class, ['record' => $rule->getRouteKey()])
            ->set('data.start_early_seconds_amount', 1440)
            ->set('data.start_early_seconds_unit', 'min')
            ->set('data.start_early_seconds_direction', 'trim')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($rule->refresh()->start_early_seconds)->toBe(-86400);
    });
});

describe('playlist DVR defaults form', function () {
    beforeEach(function () {
        Bus::fake();
        Http::preventStrayRequests();

        $this->user = User::factory()->create(['permissions' => ['use_dvr']]);
        $this->actingAs($this->user);

        $this->playlist = Playlist::factory()->for($this->user)->create();
        $this->dvrSetting = DvrSetting::factory()->enabled()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'default_start_early_seconds' => 30,
            'default_end_late_seconds' => 60,
        ]);
    });

    it('shows stored defaults as amount and unit', function () {
        $this->dvrSetting->update(['default_end_late_seconds' => 120]);

        Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
            ->assertFormSet([
                'dvr_default_start_early_seconds_amount' => 30,
                'dvr_default_start_early_seconds_unit' => 'sec',
                'dvr_default_end_late_seconds_amount' => 2,
                'dvr_default_end_late_seconds_unit' => 'min',
            ]);
    });

    it('saves an edited amount and unit as seconds, from the raw form state', function () {
        Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
            ->set('data.user_agent', 'test-agent')
            ->set('data.dvr_default_start_early_seconds_amount', 2)
            ->set('data.dvr_default_start_early_seconds_unit', 'min')
            ->set('data.dvr_default_end_late_seconds_amount', 90)
            ->set('data.dvr_default_end_late_seconds_unit', 'sec')
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->dvrSetting->refresh())
            ->default_start_early_seconds->toBe(120)
            ->default_end_late_seconds->toBe(90);
    });

    it('has no direction input and rejects defaults over 600 seconds', function () {
        Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
            ->assertFormFieldDoesNotExist('dvr_default_start_early_seconds_direction')
            ->set('data.dvr_default_start_early_seconds_amount', 11)
            ->set('data.dvr_default_start_early_seconds_unit', 'min')
            ->call('save')
            ->assertHasFormErrors(['dvr_default_start_early_seconds_amount']);

        expect($this->dvrSetting->refresh()->default_start_early_seconds)->toBe(30);
    });
});
