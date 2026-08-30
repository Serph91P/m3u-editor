<?php

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Exceptions\SchedulesDirectLoginCooldownException;
use App\Exceptions\SchedulesDirectTokenExpiredException;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Carbon\Carbon;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.key', '12345678901234567890123456789012');
    Bus::fake();
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeSchedulesDirectEpgForLoginLimitTests(array $attributes = []): Epg
{
    return Epg::factory()->for(User::factory())->create(array_merge([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'account@example.com',
        'sd_password' => 'provider-password',
        'sd_lineup_id' => 'USA-NY12345-X',
        'sd_days_to_import' => 1,
    ], $attributes));
}

function schedulesDirectTokenPayload(string $token, int $expires): array
{
    return [
        'code' => 0,
        'token' => $token,
        'tokenExpires' => $expires,
        'datetime' => '2001-01-01T00:00:00Z',
        'serverTime' => '2002-01-01T00:00:00Z',
    ];
}

it('isolates credential state by owner and the complete credential tuple', function () {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $firstOwner->id,
        'sd_token' => 'first-owner-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $secondEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $secondOwner->id,
    ]);
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('second-owner-token', $expires)),
    ]);

    $authentication = (new SchedulesDirectService)->authenticateFromEpg($secondEpg);

    expect($firstEpg->sd_account_identifier)->toHaveLength(64)
        ->and($secondEpg->fresh()->sd_account_identifier)->toHaveLength(64)
        ->and($secondEpg->fresh()->sd_account_identifier)->not->toBe($firstEpg->sd_account_identifier)
        ->and($authentication['token'])->toBe('second-owner-token')
        ->and($firstEpg->fresh()->sd_token)->toBe('first-owner-token')
        ->and($secondEpg->fresh()->sd_token)->toBe('second-owner-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('coordinates provider cooldowns across owners and passwords while keeping notification claims per recipient', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $firstEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => ' Shared.Account@Example.com ',
        'sd_password' => 'first-password',
    ]);
    $secondEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => 'shared.account@example.com',
        'sd_password' => 'second-password',
    ]);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($firstEpg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);
    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($secondEpg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $providerIdentifier = Epg::schedulesDirectProviderAccountIdentifier('shared.account@example.com');

    expect($firstEpg->sd_account_identifier)->not->toBe($secondEpg->sd_account_identifier)
        ->and(DB::table('schedules_direct_login_cooldowns')->where('account_identifier', $providerIdentifier)->count())->toBe(1)
        ->and(DB::table('schedules_direct_login_cooldown_claims')->where('provider_account_identifier', $providerIdentifier)->count())->toBe(2)
        ->and($firstEpg->user->notifications()->count())->toBe(1)
        ->and($secondEpg->user->notifications()->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('promotes a legacy per EPG cooldown into canonical account state without extending it', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    NotificationFacade::fake();
    $cooldownUntil = now()->addHours(8);
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => ' Legacy.Account@Example.com ',
        'sd_password' => 'legacy-private-password',
        'sd_login_cooldown_until' => $cooldownUntil,
    ]);
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = [$event->message, $event->context];
    });

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(
            schedulesDirectTokenPayload('unexpected-token', now()->addDay()->timestamp),
        ),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $canonicalCooldown = DB::table('schedules_direct_login_cooldowns')
        ->where('account_identifier', Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username))
        ->first();
    $serializedOutput = json_encode([
        $canonicalCooldown,
        $epg->user->notifications()->first()?->data,
        $loggedMessages,
    ]);

    expect($canonicalCooldown)->not->toBeNull()
        ->and(Carbon::parse($canonicalCooldown->cooldown_until)->equalTo($cooldownUntil))->toBeTrue()
        ->and($serializedOutput)->not->toContain('Legacy.Account@Example.com')
        ->and($serializedOutput)->not->toContain('legacy-private-password');
    Http::assertNothingSent();
});

it('does not create a recipient claim for unauthenticated rowless cooldowns', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticate('rowless@example.com', 'rowless-password'))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    expect(DB::table('schedules_direct_login_cooldowns')->count())->toBe(1)
        ->and(DB::table('schedules_direct_login_cooldown_claims')->count())->toBe(0);
});

it('does not reuse or mutate arbitrary EPG rows during unauthenticated bare authentication', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'another-users-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('rowless-token', $expires)),
    ]);

    $authentication = (new SchedulesDirectService)->authenticate('account@example.com', 'provider-password');

    expect($authentication['token'])->toBe('rowless-token')
        ->and($epg->fresh()->sd_token)->toBe('another-users-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('keeps bare authentication mutable context within the acting owner', function () {
    $otherOwnerEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'other-owner-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $actingOwner = User::factory()->create();
    $actingOwnerEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $actingOwner->id,
    ]);
    $this->actingAs($actingOwner);
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('acting-owner-token', $expires)),
    ]);

    $authentication = (new SchedulesDirectService)->authenticate(' ACCOUNT@example.com ', 'provider-password');

    expect($authentication['token'])->toBe('acting-owner-token')
        ->and($actingOwnerEpg->fresh()->sd_token)->toBe('acting-owner-token')
        ->and($otherOwnerEpg->fresh()->sd_token)->toBe('other-owner-token');
});

it('rekeys and clears authentication state when a password or owner changes', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'old-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_login_cooldown_started_at' => now(),
        'sd_login_cooldown_until' => now()->addDay(),
        'sd_login_cooldown_notified_at' => now(),
    ]);
    $originalIdentifier = $epg->sd_account_identifier;

    $epg->update(['sd_password' => 'replacement-password']);
    $epg->refresh();

    expect($epg->sd_account_identifier)->toHaveLength(64)
        ->and($epg->sd_account_identifier)->not->toBe($originalIdentifier)
        ->and($epg->sd_token)->toBeNull()
        ->and($epg->sd_token_expires_at)->toBeNull()
        ->and($epg->sd_login_cooldown_started_at)->toBeNull()
        ->and($epg->sd_login_cooldown_until)->toBeNull()
        ->and($epg->sd_login_cooldown_notified_at)->toBeNull();

    $passwordIdentifier = $epg->sd_account_identifier;
    $replacementOwner = User::factory()->create();
    $epg->update([
        'sd_token' => 'replacement-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_login_cooldown_started_at' => now(),
        'sd_login_cooldown_until' => now()->addDay(),
        'sd_login_cooldown_notified_at' => now(),
    ]);
    $epg->update(['user_id' => $replacementOwner->id]);
    $epg->refresh();

    expect($epg->sd_account_identifier)->not->toBe($passwordIdentifier)
        ->and($epg->sd_token)->toBeNull()
        ->and($epg->sd_token_expires_at)->toBeNull()
        ->and($epg->sd_login_cooldown_started_at)->toBeNull()
        ->and($epg->sd_login_cooldown_until)->toBeNull()
        ->and($epg->sd_login_cooldown_notified_at)->toBeNull();
});

it('retries EPG authentication with refreshed credentials under the same provider lock', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $expires = now()->addHours(23)->timestamp;
    $lockKeys = [];
    $firstLock = Mockery::mock(Lock::class);
    $firstLock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(function (int $seconds, Closure $callback) use ($epg) {
            Epg::whereKey($epg->id)->firstOrFail()->update(['sd_password' => 'replacement-password']);

            return $callback();
        });
    $secondLock = Mockery::mock(Lock::class);
    $secondLock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(fn (int $seconds, Closure $callback) => $callback());
    $secondLock->shouldReceive('isOwnedByCurrentProcess')->zeroOrMoreTimes()->andReturnTrue();

    Cache::shouldReceive('lock')
        ->twice()
        ->andReturnUsing(function (string $key, int $seconds) use (&$lockKeys, $firstLock, $secondLock) {
            $lockKeys[] = $key;

            return count($lockKeys) === 1 ? $firstLock : $secondLock;
        });
    Cache::shouldReceive('get')->zeroOrMoreTimes()->andReturnNull();
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('replacement-token', $expires)),
    ]);

    (new SchedulesDirectService)->authenticateFromEpg($epg);
    $tokenRequest = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token'))->sole()[0];

    expect($lockKeys)->toHaveCount(2)
        ->and($lockKeys[1])->toBe($lockKeys[0])
        ->and($tokenRequest->data()['password'])->toBe(sha1('replacement-password'))
        ->and($epg->fresh()->sd_token)->toBe('replacement-token');
});

it('persists shared credential authentication with bounded set based statements', function () {
    $owner = User::factory()->create();
    $epgs = collect(range(1, 12))->map(fn () => makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
    ]));
    $epgUpdates = 0;
    DB::listen(function ($query) use (&$epgUpdates): void {
        if (str_starts_with(strtolower($query->sql), 'update "epgs"')) {
            $epgUpdates++;
        }
    });
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(
            schedulesDirectTokenPayload('shared-token', now()->addHours(23)->timestamp),
        ),
    ]);

    (new SchedulesDirectService)->authenticateFromEpg($epgs->first());

    expect($epgUpdates)->toBeLessThanOrEqual(4)
        ->and(Epg::query()->where('user_id', $owner->id)->where('sd_token', 'shared-token')->count())->toBe(12);
});

it('uses tokenExpires rather than provider clocks when authenticating credentials', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('fresh-token', $expires)),
    ]);

    $authentication = (new SchedulesDirectService)->authenticate('account@example.com', 'provider-password');

    expect($authentication)->toBe([
        'token' => 'fresh-token',
        'expires' => $expires,
    ]);
});

it('reuses a rowless successful authentication handoff only for the same tenant and exact credentials', function () {
    $expires = now()->addHours(23)->timestamp;
    $user = User::factory()->create();

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::sequence()
            ->push(schedulesDirectTokenPayload('rowless-token', $expires))
            ->push(schedulesDirectTokenPayload('tenant-token', $expires))
            ->push(schedulesDirectTokenPayload('different-password-token', $expires)),
    ]);

    $first = (new SchedulesDirectService)->authenticate(' Rowless@Example.com ', 'rowless-password');
    $second = (new SchedulesDirectService)->authenticate('rowless@example.com', 'rowless-password');
    $handoffKey = 'schedules-direct:authentication-handoff:'.Epg::schedulesDirectAccountIdentifier(
        0,
        'rowless@example.com',
        'rowless-password',
    );

    $this->actingAs($user);
    $third = (new SchedulesDirectService)->authenticate('rowless@example.com', 'rowless-password');
    $fourth = (new SchedulesDirectService)->authenticate('rowless@example.com', 'different-password');

    expect($first['token'])->toBe('rowless-token')
        ->and($second['token'])->toBe('rowless-token')
        ->and($third['token'])->toBe('tenant-token')
        ->and($fourth['token'])->toBe('different-password-token')
        ->and(Cache::has($handoffKey))->toBeTrue()
        ->and($handoffKey)->not->toContain('rowless@example.com')
        ->and($handoffKey)->not->toContain('rowless-password')
        ->and($handoffKey)->not->toContain(sha1('rowless-password'))
        ->and($handoffKey)->not->toContain('rowless-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(3);
});

it('expires a rowless authentication handoff at the token validity guard', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $firstExpiry = now()->addSeconds(Epg::SCHEDULES_DIRECT_TOKEN_EXPIRY_SKEW_SECONDS + 1)->timestamp;
    $secondExpiry = now()->addHour()->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::sequence()
            ->push(schedulesDirectTokenPayload('short-handoff-token', $firstExpiry))
            ->push(schedulesDirectTokenPayload('replacement-handoff-token', $secondExpiry)),
    ]);

    (new SchedulesDirectService)->authenticate('rowless@example.com', 'rowless-password');
    Carbon::setTestNow(now()->addSeconds(2));
    $authentication = (new SchedulesDirectService)->authenticate('rowless@example.com', 'rowless-password');

    expect($authentication['token'])->toBe('replacement-handoff-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(2);
});

it('reuses a persisted account token when authenticating credentials', function () {
    $expires = now()->addHour();
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => ' Account@Example.com ',
        'sd_token' => 'persisted-token',
        'sd_token_expires_at' => $expires,
    ]);
    $this->actingAs($epg->user);

    $authentication = (new SchedulesDirectService)->authenticate('account@example.com', 'provider-password');

    expect($authentication)->toBe([
        'token' => 'persisted-token',
        'expires' => $expires->timestamp,
    ]);

    Http::assertNothingSent();
});

it('does not reuse or persist tokens across different passwords for the same provider username', function () {
    $owner = User::factory()->create();
    $firstEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'first-password',
        'sd_token' => 'first-account-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $secondEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'second-password',
    ]);
    $thirdEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'second-password',
    ]);
    $fourthEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'third-password',
        'sd_token' => 'third-account-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $this->actingAs($owner);
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('second-account-token', $expires)),
    ]);

    $authentication = (new SchedulesDirectService)->authenticate('account@example.com', 'second-password');

    expect($authentication['token'])->toBe('second-account-token')
        ->and($firstEpg->fresh()->sd_token)->toBe('first-account-token')
        ->and($secondEpg->fresh()->sd_token)->toBe('second-account-token')
        ->and($thirdEpg->fresh()->sd_token)->toBe('second-account-token')
        ->and($fourthEpg->fresh()->sd_token)->toBe('third-account-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('reuses credential-equivalent tokens before enforcing an account cooldown via :authenticationPath', function (string $authenticationPath) {
    NotificationFacade::fake();
    $owner = User::factory()->create();
    $validTokenEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'reusable-password',
        'sd_token' => 'still-valid-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $requestingEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'reusable-password',
    ]);
    $differentCredentialEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'limited-password',
    ]);
    $this->actingAs($owner);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticate('account@example.com', 'limited-password'))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $differentCredentialEpg->refresh();
    $cooldownStartedAt = $differentCredentialEpg->sd_login_cooldown_started_at;
    $cooldownUntil = $differentCredentialEpg->sd_login_cooldown_until;
    $service = new SchedulesDirectService;

    $authentication = $authenticationPath === 'bare credentials'
        ? $service->authenticate('account@example.com', 'reusable-password')
        : $service->authenticateFromEpg($requestingEpg);

    expect($authentication['token'])->toBe('still-valid-token')
        ->and($requestingEpg->fresh()->sd_token)->toBe(
            $authenticationPath === 'EPG credentials' ? 'still-valid-token' : null,
        )
        ->and($validTokenEpg->fresh()->sd_login_cooldown_started_at->equalTo($cooldownStartedAt))->toBeTrue()
        ->and($validTokenEpg->fresh()->sd_login_cooldown_until->equalTo($cooldownUntil))->toBeTrue()
        ->and($requestingEpg->fresh()->sd_login_cooldown_started_at->equalTo($cooldownStartedAt))->toBeTrue()
        ->and($requestingEpg->fresh()->sd_login_cooldown_until->equalTo($cooldownUntil))->toBeTrue()
        ->and($differentCredentialEpg->fresh()->sd_login_cooldown_started_at->equalTo($cooldownStartedAt))->toBeTrue()
        ->and($differentCredentialEpg->fresh()->sd_login_cooldown_until->equalTo($cooldownUntil))->toBeTrue()
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
})->with(['bare credentials', 'EPG credentials']);

it('retains a safely matched EPG context for 4006 recovery after bare authentication', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $this->actingAs($epg->user);
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::sequence()
            ->push(schedulesDirectTokenPayload('initial-token', $expires))
            ->push(schedulesDirectTokenPayload('replacement-token', $expires)),
        'json.schedulesdirect.org/20141201/headends*' => Http::sequence()
            ->push(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401)
            ->push([[
                'headend' => 'Antenna',
                'lineups' => [],
            ]]),
    ]);

    $service = new SchedulesDirectService;
    $authentication = $service->authenticate('account@example.com', 'provider-password');
    $headends = $service->getHeadends($authentication['token'], 'USA', '10001');

    expect($headends)->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/headends')))->toHaveCount(2);
});

it('uses tokenExpires when authenticating from an EPG and persists that expiry', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $expires = now()->addHours(23)->timestamp;
    $epg = makeSchedulesDirectEpgForLoginLimitTests();

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('fresh-token', $expires)),
    ]);

    $authentication = (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($authentication['expires'])->toBe($expires)
        ->and($epg->fresh()->sd_token)->toBe('fresh-token')
        ->and($epg->fresh()->sd_token_expires_at->timestamp)->toBe($expires);
});

it('uses the documented 24 hour fallback when EPG authentication has no tokenExpires', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $epg = makeSchedulesDirectEpgForLoginLimitTests();

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 0,
            'token' => 'secret-provider-token',
            'datetime' => now()->toIso8601String(),
        ]),
    ]);

    $authentication = (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($authentication['expires'])->toBe(now()->addDay()->timestamp)
        ->and($epg->fresh()->sd_token)->toBe('secret-provider-token')
        ->and($epg->fresh()->sd_token_expires_at->timestamp)->toBe(now()->addDay()->timestamp);
});

it('fails closed with a sanitized error for an invalid token expiry', function (mixed $tokenExpires) {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $payload = schedulesDirectTokenPayload('secret-provider-token', now()->addDay()->timestamp);

    if ($tokenExpires === null) {
        unset($payload['tokenExpires']);
    } else {
        $payload['tokenExpires'] = $tokenExpires;
    }

    $payload['message'] = 'raw provider response account@example.com provider-password';

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response($payload),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticate('account@example.com', 'provider-password'))
        ->toThrow(Exception::class, 'Schedules Direct authentication returned an invalid token expiry.');
})->with([
    'numeric string' => '1796040000',
    'floating point' => 1796040000.5,
    'expired' => 1788091199,
    'inside safety window' => 1788091499,
]);

it('applies a five minute safety skew to stored tokens', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');

    $insideGuard = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'inside-guard-token',
        'sd_token_expires_at' => now()->addMinutes(5),
    ]);
    $outsideGuard = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'outside-guard-token',
        'sd_token_expires_at' => now()->addMinutes(5)->addSecond(),
    ]);

    expect($insideGuard->hasValidSchedulesDirectToken())->toBeFalse()
        ->and($outsideGuard->hasValidSchedulesDirectToken())->toBeTrue();
});

it('preserves authentication for a normalized equivalent username and invalidates a changed username', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'account-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_login_cooldown_started_at' => now(),
        'sd_login_cooldown_until' => now()->addDay(),
        'sd_login_cooldown_notified_at' => now(),
    ]);

    $epg->update(['sd_username' => ' ACCOUNT@example.com ']);

    expect($epg->fresh()->sd_token)->toBe('account-token')
        ->and($epg->fresh()->sd_login_cooldown_until)->not->toBeNull();

    $epg->update(['sd_username' => 'different@example.com']);
    $epg->refresh();

    expect($epg->sd_account_identifier)->toBe(Epg::schedulesDirectAccountIdentifier(
        $epg->user_id,
        'different@example.com',
        $epg->sd_password,
    ))
        ->and($epg->sd_token)->toBeNull()
        ->and($epg->sd_token_expires_at)->toBeNull()
        ->and($epg->sd_login_cooldown_started_at)->toBeNull()
        ->and($epg->sd_login_cooldown_until)->toBeNull()
        ->and($epg->sd_login_cooldown_notified_at)->toBeNull();
});

it('reuses a valid token for account lineups removal and image proxy validation', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);

    Http::fake(function (Request $request) {
        if ($request->url() === 'https://json.schedulesdirect.org/20141201/lineups' && $request->method() === 'GET') {
            return Http::response(['lineups' => [[
                'lineup' => 'USA-NY12345-X',
                'name' => 'Test Lineup',
                'transport' => 'Antenna',
            ]]]);
        }

        if ($request->url() === 'https://json.schedulesdirect.org/20141201/lineups/USA-NY12345-X' && $request->method() === 'DELETE') {
            return Http::response(['code' => 0]);
        }

        if ($request->url() === 'https://json.schedulesdirect.org/20141201/image/test-image') {
            return Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response(['unexpected' => true], 500);
    });

    $service = new SchedulesDirectService;

    expect($service->getAccountLineupsAsOptions($epg))->toHaveKey('USA-NY12345-X');
    $service->removeLineupFromEpg($epg, 'USA-NY12345-X');
    $this->get(route('schedules-direct.image.proxy', [
        'epg' => $epg->uuid,
        'imageHash' => 'test-image',
    ]))->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/token'));
});

it('reuses a valid token during a Schedules Direct sync', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);

    Storage::fake('local');
    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [['stationID' => '12345', 'channel' => '1.1']],
            'stations' => [['stationID' => '12345', 'name' => 'Test', 'callsign' => 'TEST']],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            ['stationID' => '12345', 'programs' => []],
        ]),
    ]);

    (new SchedulesDirectService)->syncEpgData($epg);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/token'));
});

it('single flights authentication per normalized keyed account and reuses account state', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $expires = now()->addHours(23)->timestamp;
    $owner = User::factory()->create();
    $firstEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_username' => ' Shared.Account@Example.com ',
    ]);
    $secondEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_username' => 'shared.account@example.com',
    ]);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('account-token', $expires)),
    ]);

    $service = new SchedulesDirectService;
    $service->authenticateFromEpg($firstEpg);
    $service->authenticateFromEpg($secondEpg);

    $tokenRequests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token'));
    $firstEpg->refresh();
    $secondEpg->refresh();

    expect($tokenRequests)->toHaveCount(1)
        ->and($firstEpg->sd_account_identifier)->not->toBeNull()
        ->and($secondEpg->sd_account_identifier)->toBe($firstEpg->sd_account_identifier)
        ->and($secondEpg->sd_token)->toBe('account-token')
        ->and($secondEpg->sd_token_expires_at->timestamp)->toBe($expires);
});

it('uses a bounded lock whose key contains no account secrets', function () {
    $username = 'Sensitive.Account@Example.com';
    $password = 'highly-sensitive-password';
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => $username,
        'sd_password' => $password,
    ]);
    $expires = now()->addHours(23)->timestamp;
    $capturedLockKey = null;
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(fn (int $seconds, Closure $callback) => $callback());
    $lock->shouldReceive('isOwnedByCurrentProcess')->zeroOrMoreTimes()->andReturnTrue();

    Cache::shouldReceive('lock')
        ->once()
        ->withArgs(function (string $key, int $seconds) use (&$capturedLockKey): bool {
            $capturedLockKey = $key;

            return $seconds === 180;
        })
        ->andReturn($lock);
    Cache::shouldReceive('get')->zeroOrMoreTimes()->andReturnNull();

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('secret-token', $expires)),
    ]);

    (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($capturedLockKey)->toBe(
        'schedules-direct:authentication:'.Epg::schedulesDirectProviderAccountIdentifier($username),
    )
        ->and($capturedLockKey)->not->toContain(strtolower(trim($username)))
        ->and($capturedLockKey)->not->toContain($password)
        ->and($capturedLockKey)->not->toContain(sha1($password))
        ->and($capturedLockKey)->not->toContain('secret-token');
});

it('fails closed without authenticating when the account lock wait times out', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException);

    Cache::shouldReceive('lock')
        ->once()
        ->with(Mockery::on(fn (string $key): bool => str_starts_with($key, 'schedules-direct:authentication:')), 180)
        ->andReturn($lock);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(Exception::class, 'Schedules Direct authentication is already in progress. Please try again shortly.');

    Http::assertNothingSent();
});

it('acquires and releases the PostgreSQL provider advisory lock on success', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_try_advisory_lock')), Mockery::type('array'))
        ->andReturn((object) ['acquired' => true]);
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_advisory_unlock')), Mockery::type('array'))
        ->andReturn((object) ['released' => true]);
    $method = new ReflectionMethod(SchedulesDirectService::class, 'withProviderAccountAdvisoryLock');

    $result = $method->invoke(
        new SchedulesDirectService,
        Epg::schedulesDirectProviderAccountIdentifier('account@example.com'),
        fn (): array => ['persisted' => true],
    );

    expect($result)->toBe(['persisted' => true]);
});

it('releases the provider advisory lock when the critical section throws', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_try_advisory_lock')), Mockery::type('array'))
        ->andReturn((object) ['acquired' => true]);
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_advisory_unlock')), Mockery::type('array'))
        ->andReturn((object) ['released' => true]);
    $method = new ReflectionMethod(SchedulesDirectService::class, 'withProviderAccountAdvisoryLock');

    expect(fn () => $method->invoke(
        new SchedulesDirectService,
        Epg::schedulesDirectProviderAccountIdentifier('account@example.com'),
        fn () => throw new RuntimeException('controlled persistence failure'),
    ))->toThrow(RuntimeException::class, 'controlled persistence failure');
});

it('bounds PostgreSQL provider advisory lock acquisition without a real wait', function () {
    Sleep::fake();
    $connection = Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('selectOne')
        ->times(100)
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_try_advisory_lock')), Mockery::type('array'))
        ->andReturn((object) ['acquired' => false]);
    DB::shouldNotReceive('selectOne')->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_advisory_unlock')), Mockery::type('array'));
    $method = new ReflectionMethod(SchedulesDirectService::class, 'withProviderAccountAdvisoryLock');

    try {
        expect(fn () => $method->invoke(
            new SchedulesDirectService,
            Epg::schedulesDirectProviderAccountIdentifier('account@example.com'),
            fn (): array => ['unexpected' => true],
        ))->toThrow(Exception::class, 'Schedules Direct authentication is already in progress. Please try again shortly.');
        Sleep::assertSleptTimes(99);
    } finally {
        Sleep::fake(false);
    }
});

it('keeps a persistence stall fenced after the cache lease can be reacquired', function () {
    Sleep::fake();
    $connection = Mockery::mock();
    $connection->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
    DB::shouldReceive('connection')->twice()->andReturn($connection);
    $acquisitionAttempt = 0;
    DB::shouldReceive('selectOne')
        ->times(101)
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_try_advisory_lock')), Mockery::type('array'))
        ->andReturnUsing(function () use (&$acquisitionAttempt): object {
            $acquisitionAttempt++;

            return (object) ['acquired' => $acquisitionAttempt === 1];
        });
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'pg_advisory_unlock')), Mockery::type('array'))
        ->andReturn((object) ['released' => true]);
    $method = new ReflectionMethod(SchedulesDirectService::class, 'withProviderAccountAdvisoryLock');
    $providerIdentifier = Epg::schedulesDirectProviderAccountIdentifier('account@example.com');
    $overlapFailure = null;
    $persisted = false;

    try {
        $method->invoke(new SchedulesDirectService, $providerIdentifier, function () use ($method, $providerIdentifier, &$overlapFailure, &$persisted): array {
            try {
                $method->invoke(new SchedulesDirectService, $providerIdentifier, fn (): array => ['unexpected' => true]);
            } catch (Throwable $throwable) {
                $overlapFailure = $throwable;
            }

            $persisted = true;

            return ['persisted' => true];
        });
    } finally {
        Sleep::fake(false);
    }

    expect($persisted)->toBeTrue()
        ->and($overlapFailure)->toBeInstanceOf(Exception::class)
        ->and($overlapFailure->getMessage())->toBe('Schedules Direct authentication is already in progress. Please try again shortly.');
});

it('persists successful authentication after the cache lease expires while the PostgreSQL advisory fence is active', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only advisory lock assertion.');
    }

    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $expires = now()->addHours(23)->timestamp;
    $cacheLeaseExpired = false;
    $advisoryFenceWasActive = false;
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(fn (int $seconds, Closure $callback): array => $callback());
    $lock->shouldReceive('isOwnedByCurrentProcess')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function () use (&$cacheLeaseExpired): bool {
            return ! $cacheLeaseExpired;
        });

    Cache::shouldReceive('lock')
        ->once()
        ->with(Mockery::on(fn (string $key): bool => str_starts_with($key, 'schedules-direct:authentication:')), 180)
        ->andReturn($lock);
    Cache::shouldReceive('get')->zeroOrMoreTimes()->andReturnNull();

    config()->set('database.connections.pg_advisory_probe', config('database.connections.'.config('database.default')));
    DB::purge('pg_advisory_probe');
    $keys = (new ReflectionMethod(SchedulesDirectService::class, 'postgresAdvisoryLockKeys'))
        ->invoke(new SchedulesDirectService, Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username));

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => function () use (&$advisoryFenceWasActive, &$cacheLeaseExpired, $expires, $keys) {
            $probe = DB::connection('pg_advisory_probe')
                ->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', $keys);
            $probeAcquired = in_array($probe?->acquired, [true, 1, '1', 't'], true);
            $advisoryFenceWasActive = ! $probeAcquired;

            if ($probeAcquired) {
                DB::connection('pg_advisory_probe')
                    ->selectOne('SELECT pg_advisory_unlock(?, ?) AS released', $keys);
            }

            $cacheLeaseExpired = true;

            return Http::response(schedulesDirectTokenPayload('advisory-fenced-token', $expires));
        },
    ]);

    try {
        $authentication = (new SchedulesDirectService)->authenticateFromEpg($epg);
        $releaseProbe = DB::connection('pg_advisory_probe')
            ->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', $keys);
        $advisoryFenceWasReleased = in_array($releaseProbe?->acquired, [true, 1, '1', 't'], true);

        if ($advisoryFenceWasReleased) {
            DB::connection('pg_advisory_probe')
                ->selectOne('SELECT pg_advisory_unlock(?, ?) AS released', $keys);
        }
    } finally {
        DB::disconnect('pg_advisory_probe');
    }

    expect($advisoryFenceWasActive)->toBeTrue()
        ->and($advisoryFenceWasReleased)->toBeTrue()
        ->and($cacheLeaseExpired)->toBeTrue()
        ->and($authentication['token'])->toBe('advisory-fenced-token')
        ->and($epg->fresh()->sd_token)->toBe('advisory-fenced-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('fails closed when the cache lease expires without a database advisory fence', function () {
    if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Requires a database driver without advisory lock support.');
    }

    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $cacheLeaseExpired = false;
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(fn (int $seconds, Closure $callback): array => $callback());
    $lock->shouldReceive('isOwnedByCurrentProcess')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function () use (&$cacheLeaseExpired): bool {
            return ! $cacheLeaseExpired;
        });

    Cache::shouldReceive('lock')->once()->andReturn($lock);
    Cache::shouldReceive('get')->zeroOrMoreTimes()->andReturnNull();
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => function () use (&$cacheLeaseExpired) {
            $cacheLeaseExpired = true;

            return Http::response(schedulesDirectTokenPayload('discarded-token', now()->addHours(23)->timestamp));
        },
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(Exception::class, 'Schedules Direct authentication lease expired. Please try again shortly.');

    expect($epg->fresh()->sd_token)->toBeNull()
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('fails closed for unsupported production database authentication fencing', function () {
    $originalEnvironment = app()->environment();
    $connection = Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('sqlsrv');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    $method = new ReflectionMethod(SchedulesDirectService::class, 'withProviderAccountAdvisoryLock');

    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => $method->invoke(
            new SchedulesDirectService,
            Epg::schedulesDirectProviderAccountIdentifier('account@example.com'),
            fn (): array => ['unexpected' => true],
        ))->toThrow(Exception::class, 'Schedules Direct authentication requires a database driver with advisory lock support.');
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
});

it('uses a bounded MySQL provider advisory lock and releases it', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'GET_LOCK')), Mockery::on(fn (array $bindings): bool => $bindings[1] === 5))
        ->andReturn((object) ['acquired' => 1]);
    DB::shouldReceive('selectOne')
        ->once()
        ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, 'RELEASE_LOCK')), Mockery::type('array'))
        ->andReturn((object) ['released' => 1]);
    $method = new ReflectionMethod(SchedulesDirectService::class, 'withProviderAccountAdvisoryLock');

    $result = $method->invoke(
        new SchedulesDirectService,
        Epg::schedulesDirectProviderAccountIdentifier('account@example.com'),
        fn (): array => ['persisted' => true],
    );

    expect($result)->toBe(['persisted' => true]);
});

it('invalidates and reauthenticates once when an authenticated request returns 4006', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $expires = now()->addHours(23)->timestamp;
    $owner = User::factory()->create();
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_token' => 'expired-provider-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $sameAccountEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_username' => ' ACCOUNT@example.com ',
        'sd_token' => 'expired-provider-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $differentCredentialEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_password' => 'different-password',
        'sd_token' => 'different-credential-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $lineupResponses = Http::sequence()
        ->push(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401)
        ->push(['lineups' => [[
            'lineup' => 'USA-NY12345-X',
            'name' => 'Test Lineup',
            'transport' => 'Antenna',
        ]]]);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups' => $lineupResponses,
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('replacement-token', $expires)),
    ]);

    $options = (new SchedulesDirectService)->getAccountLineupsAsOptions($epg);
    $recorded = Http::recorded();
    $lineupRequests = $recorded->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/lineups'));
    $tokenRequests = $recorded->filter(fn (array $record): bool => str_ends_with($record[0]->url(), '/token'));

    expect($options)->toHaveKey('USA-NY12345-X')
        ->and($lineupRequests)->toHaveCount(2)
        ->and($lineupRequests->first()[0]->header('token'))->toBe(['expired-provider-token'])
        ->and($lineupRequests->last()[0]->header('token'))->toBe(['replacement-token'])
        ->and($tokenRequests)->toHaveCount(1)
        ->and($epg->fresh()->sd_token)->toBe('replacement-token')
        ->and($sameAccountEpg->fresh()->sd_token)->toBe('replacement-token')
        ->and($differentCredentialEpg->fresh()->sd_token)->toBe('different-credential-token');
});

it('does not repeat 4006 recovery more than once for a request', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'expired-provider-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups' => Http::sequence()
            ->push(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401)
            ->push(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401),
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('replacement-token', $expires)),
    ]);

    expect(fn () => (new SchedulesDirectService)->getAccountLineupsAsOptions($epg))
        ->toThrow(SchedulesDirectTokenExpiredException::class, 'Schedules Direct token remained expired after one recovery attempt.');

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/lineups')))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('surfaces a typed failure when artwork token recovery cannot authenticate', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'expired-artwork-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $service = (new SchedulesDirectService)->setCurrentEpg($epg);

    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/programs/*' => Http::response(['code' => 4006, 'message' => 'TOKEN_EXPIRED']),
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 4999, 'message' => 'Authentication unavailable'], 503),
    ]);

    expect(fn () => $service->getProgramArtwork($epg->sd_token, ['EP000000000001']))
        ->toThrow(SchedulesDirectTokenExpiredException::class, 'Schedules Direct token expired and could not be refreshed.');

    expect($epg->fresh()->sd_token)->toBeNull();
});

it('clears a provider-rejected token before surfacing an active login cooldown', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'rejected-during-cooldown',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $providerIdentifier = Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username);
    DB::table('schedules_direct_login_cooldowns')->insert([
        'account_identifier' => $providerIdentifier,
        'started_at' => now()->subHour(),
        'cooldown_until' => now()->addHours(8),
        'notified_at' => null,
    ]);
    $service = (new SchedulesDirectService)->setCurrentEpg($epg);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response(['code' => 4006, 'message' => 'TOKEN_EXPIRED']),
    ]);

    expect(fn () => $service->getLineup($epg->sd_token, $epg->sd_lineup_id))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    expect($epg->fresh()->sd_token)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/token'));
});

it('reuses a token refreshed by another worker while waiting for 4006 recovery', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'rejected-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $lineupRequestCount = 0;

    Http::fake(function (Request $request) use ($epg, &$lineupRequestCount) {
        if (! str_ends_with($request->url(), '/lineups')) {
            return Http::response(['unexpected' => true], 500);
        }

        $lineupRequestCount++;

        if ($lineupRequestCount === 1) {
            Epg::whereKey($epg->id)->update([
                'sd_token' => 'concurrently-refreshed-token',
                'sd_token_expires_at' => now()->addHour(),
            ]);

            return Http::response(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401);
        }

        return Http::response(['lineups' => [[
            'lineup' => 'USA-NY12345-X',
            'name' => 'Test Lineup',
            'transport' => 'Antenna',
        ]]]);
    });

    $options = (new SchedulesDirectService)->getAccountLineupsAsOptions($epg);
    $lineupRequests = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/lineups'));

    expect($options)->toHaveKey('USA-NY12345-X')
        ->and($lineupRequests)->toHaveCount(2)
        ->and($lineupRequests->first()[0]->header('token'))->toBe(['rejected-token'])
        ->and($lineupRequests->last()[0]->header('token'))->toBe(['concurrently-refreshed-token']);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/token'));
});

it('replays a direct authenticated metadata request once after 4006', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'expired-provider-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $expires = now()->addHours(23)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/programs/' => Http::sequence()
            ->push(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401)
            ->push([[
                'programID' => 'EP123',
                'data' => [[
                    'uri' => 'artwork/EP123.jpg',
                    'category' => 'poster art',
                    'width' => 1000,
                    'height' => 1500,
                ]],
            ]]),
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('replacement-token', $expires)),
    ]);

    $artwork = (new SchedulesDirectService)
        ->setCurrentEpg($epg)
        ->getProgramArtwork('expired-provider-token', ['EP123']);

    expect($artwork)->toHaveKey('EP123')
        ->and($artwork['EP123'][0]['url'])->toContain('artwork/EP123.jpg')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/metadata/programs/')))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('replays a streamed program request once after 4006', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'expired-provider-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $expires = now()->addHours(23)->timestamp;
    Storage::fake('local');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [['stationID' => '12345', 'channel' => '1.1']],
            'stations' => [['stationID' => '12345', 'name' => 'Test', 'callsign' => 'TEST']],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([[
            'stationID' => '12345',
            'programs' => [[
                'programID' => 'EP123',
                'airDateTime' => '2026-08-30T20:00:00Z',
                'duration' => 3600,
            ]],
        ]]),
        'json.schedulesdirect.org/20141201/programs' => Http::sequence()
            ->push(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401)
            ->push([[
                'programID' => 'EP123',
                'titles' => [['title120' => 'Recovered Program']],
                'descriptions' => ['description1000' => [['description' => 'Recovered description']]],
            ]]),
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('replacement-token', $expires)),
    ]);

    (new SchedulesDirectService)->syncEpgData($epg);

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/programs')))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1)
        ->and(Storage::disk('local')->get($epg->file_path))->toContain('Recovered Program');
});

it('propagates and persists a 4009 received while recovering from 4006', function () {
    NotificationFacade::fake();
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'expired-provider-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/programs/' => Http::response([
            'code' => 4006,
            'message' => 'TOKEN_EXPIRED',
        ], 401),
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    $service = (new SchedulesDirectService)->setCurrentEpg($epg);

    expect(fn () => $service->getProgramArtwork('expired-provider-token', ['EP123']))
        ->toThrow(Exception::class, 'Schedules Direct authentication is paused until');
    expect(fn () => $service->authenticateFromEpg($epg))
        ->toThrow(Exception::class, 'Schedules Direct authentication is paused until');

    expect($epg->fresh()->hasActiveSchedulesDirectLoginCooldown())->toBeTrue()
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/metadata/programs/')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('does not expose provider response bodies or account secrets in request failures', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => 'private.account@example.com',
        'sd_password' => 'private-password',
        'sd_token' => 'private-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = [$event->level, $event->message, $event->context];
    });

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups' => Http::response([
            'code' => 4999,
            'message' => 'raw-provider-response private.account@example.com private-password private-token',
        ], 400),
    ]);

    $exception = null;
    try {
        (new SchedulesDirectService)->getAccountLineupsAsOptions($epg);
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }

    $serializedFailure = $exception?->getMessage().json_encode($loggedMessages);

    expect($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getCode())->toBe(4999)
        ->and($serializedFailure)->not->toContain('raw-provider-response')
        ->and($serializedFailure)->not->toContain('private.account@example.com')
        ->and($serializedFailure)->not->toContain('private-password')
        ->and($serializedFailure)->not->toContain('private-token');
});

it('does not retry provider 4xx responses through the generic HTTP retry policy', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups' => Http::response([
            'code' => 4999,
            'message' => 'controlled client failure',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->getAccountLineupsAsOptions($epg))
        ->toThrow(Exception::class, 'Schedules Direct API request failed (code 4999).');

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/lineups')))->toHaveCount(1);
});

it('keeps the 2055 debug retry bounded', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests(['sd_debug' => true]);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::sequence()
            ->push(['code' => 2055, 'message' => 'DEBUG_NOT_ENABLED'])
            ->push(['code' => 2055, 'message' => 'DEBUG_NOT_ENABLED']),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(Exception::class, 'Schedules Direct authentication failed (code 2055).');

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(2)
        ->and($epg->fresh()->sd_debug)->toBeFalse();
});

it('starts one non extending account cooldown and sends one sanitized database notification', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    NotificationFacade::fake();
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = [$event->level, $event->message, $event->context];
    });

    $username = 'Sensitive.Account@Example.com';
    $password = 'highly-sensitive-password';
    $oldToken = 'existing-sensitive-token';
    $owner = User::factory()->create();
    $firstEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_username' => " {$username} ",
        'sd_password' => $password,
        'sd_token' => $oldToken,
        'sd_token_expires_at' => now()->subMinute(),
    ]);
    $secondEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_username' => strtolower($username),
        'sd_password' => $password,
    ]);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => "raw-provider-body {$username} {$password}",
            'token' => 'provider-error-token',
        ], 400),
    ]);

    $firstException = null;
    try {
        (new SchedulesDirectService)->authenticateFromEpg($firstEpg);
    } catch (Throwable $exception) {
        $firstException = $exception;
    }

    $firstEpg->refresh();
    $secondEpg->refresh();
    $startedAt = $firstEpg->sd_login_cooldown_started_at;
    $endsAt = $firstEpg->sd_login_cooldown_until;

    expect($firstException)->not->toBeNull()
        ->and($startedAt->equalTo(now()))->toBeTrue()
        ->and($endsAt->equalTo(now()->addDay()))->toBeTrue()
        ->and($secondEpg->sd_login_cooldown_started_at->equalTo($startedAt))->toBeTrue()
        ->and($secondEpg->sd_login_cooldown_until->equalTo($endsAt))->toBeTrue();

    Carbon::setTestNow(now()->addHour());

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($secondEpg))
        ->toThrow(Exception::class);

    $firstEpg->refresh();
    $secondEpg->refresh();
    $notifications = NotificationFacade::sent($firstEpg->user, DatabaseNotification::class);
    $sensitiveValues = [
        $username,
        strtolower($username),
        $password,
        sha1($password),
        $oldToken,
        'provider-error-token',
        'raw-provider-body',
        $firstEpg->sd_account_identifier,
    ];
    $serializedNotification = $notifications->map->toArray()->toJson();
    $exceptionMessage = $firstException->getMessage();

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1)
        ->and($firstEpg->sd_login_cooldown_started_at->equalTo($startedAt))->toBeTrue()
        ->and($firstEpg->sd_login_cooldown_until->equalTo($endsAt))->toBeTrue()
        ->and($secondEpg->sd_login_cooldown_started_at->equalTo($startedAt))->toBeTrue()
        ->and($secondEpg->sd_login_cooldown_until->equalTo($endsAt))->toBeTrue()
        ->and($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['body'])->toContain($endsAt->toIso8601String());

    foreach ($sensitiveValues as $sensitiveValue) {
        expect($exceptionMessage)->not->toContain($sensitiveValue)
            ->and($serializedNotification)->not->toContain($sensitiveValue)
            ->and(json_encode($loggedMessages))->not->toContain($sensitiveValue);
    }
});

it('persists a rowless bare authentication cooldown and notifies the acting user once', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $user = User::factory()->create();
    $this->actingAs($user);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticate('rowless@example.com', 'rowless-password'))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $cooldown = DB::table('schedules_direct_login_cooldowns')->first();
    $notification = $user->notifications()->first();

    expect(Epg::query()->count())->toBe(0)
        ->and(DB::table('schedules_direct_login_cooldowns')->count())->toBe(1)
        ->and(Carbon::parse($cooldown->started_at)->equalTo(now()))->toBeTrue()
        ->and(Carbon::parse($cooldown->cooldown_until)->equalTo(now()->addDay()))->toBeTrue()
        ->and($cooldown->notified_at)->toBeNull()
        ->and(DB::table('schedules_direct_login_cooldown_claims')->where('user_id', $user->id)->value('notified_at'))->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1)
        ->and($notification->data['title'])->toBe('Schedules Direct login limit reached');
});

it('suppresses repeated rowless bare authentication without extending cooldown or duplicating notification', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $user = User::factory()->create();
    $this->actingAs($user);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    $service = new SchedulesDirectService;
    expect(fn () => $service->authenticate('rowless@example.com', 'rowless-password'))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $firstCooldown = DB::table('schedules_direct_login_cooldowns')->first();
    Carbon::setTestNow(now()->addHour());

    expect(fn () => $service->authenticate(' ROWLESS@example.com ', 'rowless-password'))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $secondCooldown = DB::table('schedules_direct_login_cooldowns')->first();

    expect($secondCooldown->started_at)->toBe($firstCooldown->started_at)
        ->and($secondCooldown->cooldown_until)->toBe($firstCooldown->cooldown_until)
        ->and($user->notifications()->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('does not restore a stale EPG mirror after the canonical cooldown expires', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $username = 'canonical-expired@example.com';
    $password = 'canonical-expired-password';
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => $username,
        'sd_password' => $password,
        'sd_login_cooldown_started_at' => now()->subHour(),
        'sd_login_cooldown_until' => now()->addDay(),
    ]);
    $this->actingAs($epg->user);
    $accountIdentifier = Epg::schedulesDirectProviderAccountIdentifier($username);

    DB::table('schedules_direct_login_cooldowns')->insert([
        'account_identifier' => $accountIdentifier,
        'started_at' => now()->subDays(2),
        'cooldown_until' => now()->subDay(),
        'notified_at' => now()->subDays(2),
    ]);
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(
            schedulesDirectTokenPayload('canonical-resumed-token', now()->addDays(2)->timestamp),
        ),
    ]);

    $authentication = (new SchedulesDirectService)->authenticate($username, $password);
    $epg->refresh();

    expect($authentication['token'])->toBe('canonical-resumed-token')
        ->and(DB::table('schedules_direct_login_cooldowns')->count())->toBe(0)
        ->and($epg->sd_login_cooldown_started_at)->toBeNull()
        ->and($epg->sd_login_cooldown_until)->toBeNull()
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('resumes rowless bare authentication after cooldown expiry and clears canonical state', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $user = User::factory()->create();
    $this->actingAs($user);
    $expires = now()->addDays(3)->timestamp;

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::sequence()
            ->push([
                'code' => 4009,
                'message' => 'TOO_MANY_LOGINS',
            ], 400)
            ->push(schedulesDirectTokenPayload('resumed-token', $expires)),
    ]);

    $service = new SchedulesDirectService;
    expect(fn () => $service->authenticate('rowless@example.com', 'rowless-password'))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    Carbon::setTestNow(now()->addDay()->addSecond());
    $authentication = $service->authenticate('rowless@example.com', 'rowless-password');

    expect($authentication['token'])->toBe('resumed-token')
        ->and(DB::table('schedules_direct_login_cooldowns')->count())->toBe(0)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(2);
});

it('keeps rowless canonical cooldown state and notification free of provider secrets', function () {
    $username = 'rowless.private@example.com';
    $password = 'rowless-private-password';
    $providerToken = 'rowless-provider-token';
    $providerBody = 'rowless-raw-provider-response';
    $user = User::factory()->create();
    $this->actingAs($user);
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = [$event->level, $event->message, $event->context];
    });

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => "{$providerBody} {$username} {$password}",
            'token' => $providerToken,
        ], 400),
    ]);

    $exception = null;
    try {
        (new SchedulesDirectService)->authenticate($username, $password);
    } catch (Throwable $throwable) {
        $exception = $throwable;
    }

    $persistedOutput = json_encode([
        DB::table('schedules_direct_login_cooldowns')->first(),
        $user->notifications()->first()?->data,
        $loggedMessages,
        $exception?->getMessage(),
    ]);

    expect($exception)->toBeInstanceOf(SchedulesDirectLoginCooldownException::class)
        ->and($user->notifications()->count())->toBe(1);

    foreach ([$username, $password, sha1($password), $providerToken, $providerBody] as $secret) {
        expect($persistedOutput)->not->toContain($secret);
    }
});

it('persists cooldown independently when database notification delivery fails', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $notificationAttempts = 0;

    NotificationFacade::shouldReceive('sendNow')
        ->twice()
        ->andReturnUsing(function () use (&$notificationAttempts): void {
            $notificationAttempts++;

            if ($notificationAttempts === 1) {
                throw new RuntimeException('controlled notification failure');
            }
        });

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $epg->refresh();

    expect($epg->hasActiveSchedulesDirectLoginCooldown())->toBeTrue()
        ->and($epg->sd_login_cooldown_started_at)->not->toBeNull()
        ->and($epg->sd_login_cooldown_notified_at)->toBeNull();

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    expect($epg->fresh()->sd_login_cooldown_notified_at)->not->toBeNull()
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('rolls back the notification row and claim together when a post-insert listener fails', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $user = $epg->user()->firstOrFail();

    Event::listen(NotificationSent::class, function (NotificationSent $event): void {
        if ($event->notification instanceof DatabaseNotification) {
            throw new RuntimeException('controlled post-insert notification failure');
        }
    });

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $epg->refresh();

    expect($epg->hasActiveSchedulesDirectLoginCooldown())->toBeTrue()
        ->and($epg->sd_login_cooldown_notified_at)->toBeNull()
        ->and($user->notifications()->count())->toBe(0);

    Event::forget(NotificationSent::class);

    expect(fn () => (new SchedulesDirectService)->authenticateFromEpg($epg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    expect($epg->fresh()->sd_login_cooldown_notified_at)->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('Schedules Direct login limit reached')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('notifies the safely credential-matched owner for a bare authentication cooldown', function () {
    NotificationFacade::fake();
    $unrelatedEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_password' => 'unrelated-password',
    ]);
    $matchingEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_password' => 'matching-password',
    ]);
    $this->actingAs($matchingEpg->user);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->authenticate('account@example.com', 'matching-password'))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    NotificationFacade::assertSentTo($matchingEpg->user, DatabaseNotification::class);
    NotificationFacade::assertNotSentTo($unrelatedEpg->user, DatabaseNotification::class);

    expect($matchingEpg->fresh()->hasActiveSchedulesDirectLoginCooldown())->toBeTrue()
        ->and($unrelatedEpg->fresh()->hasActiveSchedulesDirectLoginCooldown())->toBeTrue();
});

it('does not send a generic process failure notification for a login cooldown', function () {
    NotificationFacade::fake();
    $epg = makeSchedulesDirectEpgForLoginLimitTests();

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    (new ProcessEpgImport($epg, force: true))->handle(new SchedulesDirectService);

    $notifications = NotificationFacade::sent($epg->user, DatabaseNotification::class);
    $titles = $notifications->pluck('data.title');

    expect($titles->filter(fn (string $title): bool => $title === 'Schedules Direct login limit reached'))->toHaveCount(1)
        ->and($titles->contains(fn (string $title): bool => str_starts_with($title, 'Error processing')))->toBeFalse()
        ->and($epg->fresh()->status->value)->toBe('failed');
});

it('sanitizes authentication database failures before service and job error handling', function () {
    NotificationFacade::fake();
    $password = 'query-exception-plaintext-password';
    $safeError = 'Schedules Direct authentication could not be completed. Please try again.';
    $epg = makeSchedulesDirectEpgForLoginLimitTests(['sd_password' => $password]);
    $loggedMessages = [];
    $injectedFailures = 0;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = [$event->level, $event->message, $event->context];
    });
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$injectedFailures, $password): void {
        if ($injectedFailures < 2
            && str_starts_with(strtolower($query), 'update')
            && str_contains(strtolower($query), 'epgs')
            && in_array($password, $bindings, true)
        ) {
            $injectedFailures++;

            throw new QueryException(
                DB::connection()->getName(),
                $query,
                $bindings,
                new PDOException('Forced database failure containing '.$password),
            );
        }
    });

    $serviceException = null;
    try {
        (new SchedulesDirectService)->syncEpgData($epg);
    } catch (Throwable $throwable) {
        $serviceException = $throwable;
    }

    (new ProcessEpgImport($epg, force: true))->handle(new SchedulesDirectService);

    $epg->refresh();
    $notifications = NotificationFacade::sent($epg->user, DatabaseNotification::class);
    $serializedOutput = json_encode([
        $loggedMessages,
        $epg->sd_errors,
        $epg->errors,
        $notifications->map->toArray()->all(),
        $serviceException?->getMessage(),
    ]);

    expect($injectedFailures)->toBe(2)
        ->and($serviceException)->toBeInstanceOf(Exception::class)
        ->and($serviceException)->not->toBeInstanceOf(QueryException::class)
        ->and($serviceException->getMessage())->toBe($safeError)
        ->and($serviceException->getPrevious())->toBeNull()
        ->and($serializedOutput)->toContain($safeError)
        ->and($serializedOutput)->not->toContain($password)
        ->and($serializedOutput)->not->toContain('Forced database failure')
        ->and($serializedOutput)->not->toContain('SQL:');

    Http::assertNothingSent();
});

it('automatically resumes after cooldown expiry and clears cooldown for the account', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $owner = User::factory()->create();
    $firstEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
    ]);
    $secondEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_username' => ' ACCOUNT@example.com ',
    ]);

    $accountIdentifier = Epg::schedulesDirectAccountIdentifier(
        $owner->id,
        'account@example.com',
        'provider-password',
    );
    Epg::whereKey([$firstEpg->id, $secondEpg->id])->update([
        'sd_account_identifier' => $accountIdentifier,
        'sd_login_cooldown_started_at' => now()->subDays(2),
        'sd_login_cooldown_until' => now()->subDay(),
        'sd_login_cooldown_notified_at' => now()->subDays(2),
    ]);

    $expires = now()->addHours(23)->timestamp;
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(schedulesDirectTokenPayload('fresh-token', $expires)),
    ]);

    (new SchedulesDirectService)->authenticateFromEpg($firstEpg);

    foreach ([$firstEpg->fresh(), $secondEpg->fresh()] as $epg) {
        expect($epg->sd_login_cooldown_started_at)->toBeNull()
            ->and($epg->sd_login_cooldown_until)->toBeNull()
            ->and($epg->sd_login_cooldown_notified_at)->toBeNull();
    }
});

it('does not schedule a normal resync while a Schedules Direct cooldown is active', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 3,
        'resync_attempt' => 0,
        'sd_login_cooldown_started_at' => now(),
        'sd_login_cooldown_until' => now()->addDay(),
    ]);
    Bus::fake();

    expect(ProcessEpgImport::scheduleResyncIfNeeded($epg))->toBeFalse()
        ->and($epg->fresh()->resync_attempt)->toBe(0);

    Bus::assertNotDispatched(ProcessEpgImport::class);
});

it('short circuits an active cooldown locally without a token request', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 3,
        'sd_login_cooldown_started_at' => now(),
        'sd_login_cooldown_until' => now()->addDay(),
    ]);
    Bus::fake();

    expect(fn () => (new SchedulesDirectService)->syncEpgData($epg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    Http::assertNothingSent();
    expect(ProcessEpgImport::scheduleResyncIfNeeded($epg))->toBeFalse();
    Bus::assertNotDispatched(ProcessEpgImport::class);
});

it('propagates a program recovery cooldown through a full sync without success or short resync', function () {
    NotificationFacade::fake();
    Storage::fake('local');
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'expired-provider-token',
        'sd_token_expires_at' => now()->addHour(),
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 3,
    ]);
    Bus::fake();

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [['stationID' => '12345', 'channel' => '1.1']],
            'stations' => [['stationID' => '12345', 'name' => 'Test', 'callsign' => 'TEST']],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([[
            'stationID' => '12345',
            'programs' => [[
                'programID' => 'EP123',
                'airDateTime' => '2026-08-30T20:00:00Z',
                'duration' => 3600,
            ]],
        ]]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([
            'code' => 4006,
            'message' => 'TOKEN_EXPIRED',
        ], 401),
        'json.schedulesdirect.org/20141201/token' => Http::response([
            'code' => 4009,
            'message' => 'TOO_MANY_LOGINS',
        ], 400),
    ]);

    expect(fn () => (new SchedulesDirectService)->syncEpgData($epg))
        ->toThrow(SchedulesDirectLoginCooldownException::class);

    $epg->refresh();

    expect($epg->sd_last_sync)->toBeNull()
        ->and($epg->hasActiveSchedulesDirectLoginCooldown())->toBeTrue()
        ->and(ProcessEpgImport::scheduleResyncIfNeeded($epg))->toBeFalse();
    Bus::assertNotDispatched(ProcessEpgImport::class);
});

it('prevents an overlapping authentication from acquiring a second token inside the lease', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $outerLock = Mockery::mock(Lock::class);
    $outerLock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(fn (int $seconds, Closure $callback) => $callback());
    $outerLock->shouldReceive('isOwnedByCurrentProcess')->zeroOrMoreTimes()->andReturnTrue();
    $overlappingLock = Mockery::mock(Lock::class);
    $overlappingLock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException);

    Cache::shouldReceive('lock')
        ->twice()
        ->with(Mockery::on(fn (string $key): bool => str_starts_with($key, 'schedules-direct:authentication:')), 180)
        ->andReturn($outerLock, $overlappingLock);
    Cache::shouldReceive('get')->zeroOrMoreTimes()->andReturnNull();

    $overlapFailure = null;
    Http::fake(function (Request $request) use ($epg, &$overlapFailure) {
        if (! str_ends_with($request->url(), '/token')) {
            return Http::response(['unexpected' => true], 500);
        }

        try {
            (new SchedulesDirectService)->authenticateFromEpg($epg->fresh());
        } catch (Throwable $throwable) {
            $overlapFailure = $throwable;
        }

        return Http::response(schedulesDirectTokenPayload('single-flight-token', now()->addHours(23)->timestamp));
    });

    (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($overlapFailure)->toBeInstanceOf(Exception::class)
        ->and($overlapFailure->getMessage())->toBe('Schedules Direct authentication is already in progress. Please try again shortly.')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1)
        ->and($epg->fresh()->sd_token)->toBe('single-flight-token');
});

it('retries when credentials change after the post lock refresh before token reuse', function () {
    $owner = User::factory()->create();
    $reusableEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
        'sd_token' => 'old-credential-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $requestingEpg = makeSchedulesDirectEpgForLoginLimitTests([
        'user_id' => $owner->id,
    ]);
    $credentialsChanged = false;
    DB::listen(function ($query) use ($requestingEpg, &$credentialsChanged): void {
        if (! $credentialsChanged
            && str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'sd_token_expires_at')) {
            $credentialsChanged = true;
            $requestingEpg->update(['sd_password' => 'replacement-password']);
        }
    });
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(
            schedulesDirectTokenPayload('replacement-credential-token', now()->addHours(23)->timestamp),
        ),
    ]);

    $authentication = (new SchedulesDirectService)->authenticateFromEpg($requestingEpg);

    expect($credentialsChanged)->toBeTrue()
        ->and($authentication['token'])->toBe('replacement-credential-token')
        ->and($requestingEpg->fresh()->sd_password)->toBe('replacement-password')
        ->and($requestingEpg->fresh()->sd_token)->toBe('replacement-credential-token')
        ->and($requestingEpg->fresh()->sd_account_identifier)->not->toBe($reusableEpg->sd_account_identifier)
        ->and($reusableEpg->fresh()->sd_token)->toBe('old-credential-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('does not return an in memory valid token after the credential row changes', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'old-valid-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $concurrentEpg = $epg->fresh();
    $epgSelects = 0;
    DB::listen(function ($query) use ($concurrentEpg, &$epgSelects): void {
        if (str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'from "epgs"')) {
            $epgSelects++;

            if ($epgSelects === 2) {
                $concurrentEpg->update(['sd_password' => 'replacement-password']);
            }
        }
    });
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(
            schedulesDirectTokenPayload('replacement-valid-token', now()->addHours(23)->timestamp),
        ),
    ]);

    $authentication = (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($authentication['token'])->toBe('replacement-valid-token')
        ->and($epg->fresh()->sd_password)->toBe('replacement-password')
        ->and($epg->fresh()->sd_token)->toBe('replacement-valid-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('retries when credentials change in the final token persistence window', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $concurrentEpg = $epg->fresh();
    $credentialsChanged = false;
    DB::listen(function ($query) use ($concurrentEpg, &$credentialsChanged): void {
        if (! $credentialsChanged
            && str_starts_with(strtolower($query->sql), 'update "epgs"')
            && str_contains($query->sql, 'sd_login_cooldown_started_at')) {
            $credentialsChanged = true;
            $concurrentEpg->update(['sd_password' => 'replacement-password']);
        }
    });
    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::sequence()
            ->push(schedulesDirectTokenPayload('stale-window-token', now()->addHours(23)->timestamp))
            ->push(schedulesDirectTokenPayload('replacement-window-token', now()->addHours(23)->timestamp)),
    ]);

    $authentication = (new SchedulesDirectService)->authenticateFromEpg($epg);

    expect($credentialsChanged)->toBeTrue()
        ->and($authentication['token'])->toBe('replacement-window-token')
        ->and($epg->fresh()->sd_password)->toBe('replacement-password')
        ->and($epg->fresh()->sd_token)->toBe('replacement-window-token')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(2);
});

it('suppresses command and direct job work from canonical cooldown state before it is mirrored', function () {
    NotificationFacade::fake();
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'auto_sync' => true,
        'status' => Status::Pending,
        'synced' => null,
    ]);
    DB::table('schedules_direct_login_cooldowns')->insert([
        'account_identifier' => Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username),
        'started_at' => now(),
        'cooldown_until' => now()->addDay(),
        'notified_at' => null,
    ]);
    expect($epg->sd_login_cooldown_until)->toBeNull();
    Bus::fake();

    $this->artisan('app:refresh-epg', ['epg' => $epg->id, 'force' => 1])->assertSuccessful();

    Bus::assertNotDispatched(ProcessEpgImport::class);
    $service = Mockery::mock(SchedulesDirectService::class);
    $service->shouldNotReceive('syncEpgData');
    (new ProcessEpgImport($epg, force: true))->handle($service);

    $epg->refresh();
    expect($epg->status)->toBe(Status::Pending)
        ->and($epg->processing)->toBeFalse()
        ->and($epg->processing_started_at)->toBeNull()
        ->and($epg->synced)->toBeNull();
    NotificationFacade::assertNothingSent();
    Http::assertNothingSent();
});

it('does not suppress scheduled work that can use a valid isolated token during provider cooldown', function () {
    NotificationFacade::fake();
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'auto_sync' => true,
        'status' => Status::Pending,
        'synced' => null,
        'sd_token' => 'still-valid-isolated-token',
        'sd_token_expires_at' => now()->addHour(),
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 1,
        'resync_attempt' => 0,
    ]);
    DB::table('schedules_direct_login_cooldowns')->insert([
        'account_identifier' => Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username),
        'started_at' => now(),
        'cooldown_until' => now()->addDay(),
        'notified_at' => null,
    ]);
    Bus::fake();

    $this->artisan('app:refresh-epg', ['epg' => $epg->id, 'force' => 1])->assertSuccessful();

    Bus::assertDispatched(ProcessEpgImport::class, fn (ProcessEpgImport $job): bool => $job->epg->is($epg));
    Bus::fake();

    expect($epg->fresh()->hasActiveSchedulesDirectLoginCooldown())->toBeTrue()
        ->and(ProcessEpgImport::scheduleResyncIfNeeded($epg))->toBeTrue();
    Bus::assertDispatched(ProcessEpgImport::class);
    Http::assertNothingSent();
});

it('does not suppress refresh after canonical cooldown expiry when the EPG mirror is stale', function () {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_login_cooldown_started_at' => now()->subHour(),
        'sd_login_cooldown_until' => now()->addDay(),
    ]);
    DB::table('schedules_direct_login_cooldowns')->insert([
        'account_identifier' => Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username),
        'started_at' => now()->subDays(2),
        'cooldown_until' => now()->subDay(),
        'notified_at' => now()->subDays(2),
    ]);
    Bus::fake();

    $this->artisan('app:refresh-epg', ['epg' => $epg->id, 'force' => 1])->assertSuccessful();

    Bus::assertDispatched(ProcessEpgImport::class, fn (ProcessEpgImport $job): bool => $job->epg->is($epg));
});

it('does not dispatch a Schedules Direct EPG with an active cooldown from the refresh command', function (bool $explicit) {
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'auto_sync' => true,
        'status' => Status::Pending,
        'synced' => null,
        'sd_login_cooldown_started_at' => now(),
        'sd_login_cooldown_until' => now()->addDay(),
    ]);
    Bus::fake();

    $arguments = $explicit ? ['epg' => $epg->id, 'force' => 1] : [];
    $this->artisan('app:refresh-epg', $arguments)->assertSuccessful();

    Bus::assertNotDispatched(ProcessEpgImport::class);
    Http::assertNothingSent();
})->with([
    'batch refresh' => false,
    'explicit forced refresh' => true,
]);

it('resolves refresh cooldowns set-wise instead of querying once per EPG', function () {
    $epgs = collect(range(1, 8))->map(fn (): Epg => makeSchedulesDirectEpgForLoginLimitTests([
        'auto_sync' => true,
        'status' => Status::Pending,
        'synced' => null,
        'sd_username' => 'shared.scheduler@example.com',
    ]));
    DB::table('schedules_direct_login_cooldowns')->insert([
        'account_identifier' => Epg::schedulesDirectProviderAccountIdentifier('shared.scheduler@example.com'),
        'started_at' => now(),
        'cooldown_until' => now()->addDay(),
        'notified_at' => null,
    ]);
    $cooldownQueries = 0;
    DB::listen(function ($query) use (&$cooldownQueries): void {
        if (str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'schedules_direct_login_cooldowns')
        ) {
            $cooldownQueries++;
        }
    });
    Bus::fake();

    $this->artisan('app:refresh-epg')->assertSuccessful();

    expect($cooldownQueries)->toBe(1);
    foreach ($epgs as $epg) {
        Bus::assertNotDispatched(ProcessEpgImport::class, fn (ProcessEpgImport $job): bool => $job->epg->is($epg));
    }
});

it('returns from a direct import job before state changes or notifications during cooldown', function () {
    NotificationFacade::fake();
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'status' => Status::Pending,
        'processing' => false,
        'processing_started_at' => null,
        'sd_login_cooldown_started_at' => now(),
        'sd_login_cooldown_until' => now()->addDay(),
    ]);
    $service = Mockery::mock(SchedulesDirectService::class);
    $service->shouldNotReceive('syncEpgData');

    (new ProcessEpgImport($epg, force: true))->handle($service);

    $epg->refresh();
    expect($epg->status)->toBe(Status::Pending)
        ->and($epg->processing)->toBeFalse()
        ->and($epg->processing_started_at)->toBeNull()
        ->and($epg->synced)->toBeNull();
    NotificationFacade::assertNothingSent();
    Http::assertNothingSent();
});

it('reauthenticates and replays an image once after 4006 without logging provider content', function () {
    $username = 'image.private@example.com';
    $password = 'image-private-password';
    $oldToken = 'image-rejected-token';
    $providerSentinel = 'image-raw-provider-sentinel';
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_username' => $username,
        'sd_password' => $password,
        'sd_token' => $oldToken,
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $loggedMessages = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
        $loggedMessages[] = [$event->level, $event->message, $event->context];
    });

    Http::fake([
        'json.schedulesdirect.org/20141201/image/recoverable-image' => Http::sequence()
            ->push([
                'code' => 4006,
                'message' => "{$providerSentinel} {$username} {$password} {$oldToken}",
            ], 401)
            ->push('recovered-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        'json.schedulesdirect.org/20141201/token' => Http::response(
            schedulesDirectTokenPayload('image-replacement-token', now()->addHours(23)->timestamp),
        ),
    ]);

    $this->get(route('schedules-direct.image.proxy', [
        'epg' => $epg->uuid,
        'imageHash' => 'recoverable-image',
    ]))->assertSuccessful()->assertContent('recovered-image-bytes');

    $serializedLogs = json_encode($loggedMessages);
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/image/recoverable-image')))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1)
        ->and($epg->fresh()->sd_token)->toBe('image-replacement-token');

    foreach ([$providerSentinel, $username, $password, $oldToken] as $secret) {
        expect($serializedLogs)->not->toContain($secret);
    }
});

it('uses the refreshed token for later schedule chunks after one controlled 4006 recovery', function () {
    $oldToken = 'schedule-rejected-token';
    $newToken = 'schedule-replacement-token';
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => $oldToken,
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $requestsByToken = [];

    Http::fake(function (Request $request) use ($oldToken, $newToken, &$requestsByToken) {
        if (str_ends_with($request->url(), '/token')) {
            return Http::response(schedulesDirectTokenPayload($newToken, now()->addHours(23)->timestamp));
        }

        if (str_ends_with($request->url(), '/schedules')) {
            $token = $request->header('token')[0] ?? null;
            $requestsByToken[] = $token;

            if ($token === $oldToken) {
                return Http::response(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401);
            }

            return Http::response([['stationID' => 'station', 'programs' => []]]);
        }

        return Http::response(['unexpected' => true], 500);
    });

    $service = (new SchedulesDirectService)->setCurrentEpg($epg);
    $method = new ReflectionMethod(SchedulesDirectService::class, 'processScheduleChunks');
    $chunks = iterator_to_array($method->invoke(
        $service,
        $oldToken,
        array_map(fn (int $station): string => 'station-'.$station, range(1, 501)),
        [now()->format('Y-m-d')],
    ));

    expect($chunks)->toHaveCount(2)
        ->and($requestsByToken)->toBe([$oldToken, $newToken, $newToken])
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(1);
});

it('returns a quiet image 429 from canonical cooldown without authentication side effects', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $retryAt = now()->addMinutes(15);
    DB::table('schedules_direct_login_cooldowns')->insert([
        'account_identifier' => Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username),
        'started_at' => now(),
        'cooldown_until' => $retryAt,
        'notified_at' => null,
    ]);
    $loggedErrors = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedErrors): void {
        if ($event->level === 'error') {
            $loggedErrors[] = $event->message;
        }
    });

    $response = $this->get(route('schedules-direct.image.proxy', [
        'epg' => $epg->uuid,
        'imageHash' => 'cooldown-image',
    ]));

    $response->assertTooManyRequests()
        ->assertHeader('Retry-After', '900');
    expect(DB::table('schedules_direct_login_cooldown_claims')->count())->toBe(0)
        ->and($epg->fresh()->sd_login_cooldown_until)->toBeNull()
        ->and($loggedErrors)->toBe([]);
    Http::assertNothingSent();
});

it('returns a side effect free quiet image 429 when cooldown begins between controller and service checks', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $epg = makeSchedulesDirectEpgForLoginLimitTests();
    $retryAt = now()->addMinutes(10);
    $providerIdentifier = Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username);
    $canonicalCooldownInserted = false;
    $authenticationLockAcquisitions = 0;
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->zeroOrMoreTimes()
        ->andReturnUsing(fn (int $seconds, Closure $callback): array => $callback());
    $lock->shouldReceive('isOwnedByCurrentProcess')->zeroOrMoreTimes()->andReturnTrue();
    Cache::shouldReceive('has')->once()->andReturnFalse();
    Cache::shouldReceive('get')->zeroOrMoreTimes()->andReturnNull();
    Cache::shouldReceive('lock')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function () use (&$authenticationLockAcquisitions, $lock): Lock {
            $authenticationLockAcquisitions++;

            return $lock;
        });
    DB::listen(function ($query) use (&$canonicalCooldownInserted, $providerIdentifier, $retryAt): void {
        if (! $canonicalCooldownInserted
            && str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'schedules_direct_login_cooldowns')
        ) {
            $canonicalCooldownInserted = true;
            DB::table('schedules_direct_login_cooldowns')->insert([
                'account_identifier' => $providerIdentifier,
                'started_at' => now(),
                'cooldown_until' => $retryAt,
                'notified_at' => null,
            ]);
        }
    });
    NotificationFacade::fake();
    $loggedWarningsAndErrors = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedWarningsAndErrors): void {
        if (in_array($event->level, ['warning', 'error'], true)) {
            $loggedWarningsAndErrors[] = [$event->message, $event->context];
        }
    });

    $this->get(route('schedules-direct.image.proxy', [
        'epg' => $epg->uuid,
        'imageHash' => 'racing-cooldown-image',
    ]))->assertTooManyRequests()->assertHeader('Retry-After', '600');

    $epg->refresh();
    expect($canonicalCooldownInserted)->toBeTrue()
        ->and($authenticationLockAcquisitions)->toBe(0)
        ->and($epg->sd_login_cooldown_started_at)->toBeNull()
        ->and($epg->sd_login_cooldown_until)->toBeNull()
        ->and($epg->sd_login_cooldown_notified_at)->toBeNull()
        ->and(DB::table('schedules_direct_login_cooldown_claims')->count())->toBe(0)
        ->and($loggedWarningsAndErrors)->toBe([]);
    NotificationFacade::assertNothingSent();
    Http::assertNothingSent();
});

it('keeps image 4006 recovery quiet when a canonical cooldown appears before reauthentication', function () {
    Carbon::setTestNow('2026-08-30 12:00:00 UTC');
    $epg = makeSchedulesDirectEpgForLoginLimitTests([
        'sd_token' => 'provider-rejected-image-token',
        'sd_token_expires_at' => now()->addHour(),
    ]);
    $retryAt = now()->addMinutes(10);
    $authenticationLockAcquisitions = 0;
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->zeroOrMoreTimes()
        ->andReturnUsing(fn (int $seconds, Closure $callback): array => $callback());
    $lock->shouldReceive('isOwnedByCurrentProcess')->zeroOrMoreTimes()->andReturnTrue();
    Cache::shouldReceive('has')->once()->andReturnFalse();
    Cache::shouldReceive('get')->zeroOrMoreTimes()->andReturnNull();
    Cache::shouldReceive('forget')->zeroOrMoreTimes()->andReturnTrue();
    Cache::shouldReceive('lock')
        ->zeroOrMoreTimes()
        ->andReturnUsing(function () use (&$authenticationLockAcquisitions, $lock): Lock {
            $authenticationLockAcquisitions++;

            return $lock;
        });
    NotificationFacade::fake();
    Http::fake([
        'json.schedulesdirect.org/20141201/image/refresh-race-image' => function () use ($epg, $retryAt) {
            DB::table('schedules_direct_login_cooldowns')->insert([
                'account_identifier' => Epg::schedulesDirectProviderAccountIdentifier($epg->sd_username),
                'started_at' => now(),
                'cooldown_until' => $retryAt,
                'notified_at' => null,
            ]);

            return Http::response(['code' => 4006, 'message' => 'TOKEN_EXPIRED'], 401);
        },
    ]);

    $this->get(route('schedules-direct.image.proxy', [
        'epg' => $epg->uuid,
        'imageHash' => 'refresh-race-image',
    ]))->assertTooManyRequests()->assertHeader('Retry-After', '600');

    $epg->refresh();
    expect($authenticationLockAcquisitions)->toBe(0)
        ->and($epg->sd_login_cooldown_started_at)->toBeNull()
        ->and($epg->sd_login_cooldown_until)->toBeNull()
        ->and($epg->sd_login_cooldown_notified_at)->toBeNull()
        ->and(DB::table('schedules_direct_login_cooldown_claims')->count())->toBe(0)
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/image/refresh-race-image')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/token')))->toHaveCount(0);
    NotificationFacade::assertNothingSent();
});

it('rejects oversized streamed provider errors without reading or exposing the full body', function () {
    $providerSentinel = 'oversized-provider-secret';
    $responseFile = tempnam(sys_get_temp_dir(), 'sd_oversized_error_');
    file_put_contents(
        $responseFile,
        '{"message":"'.str_repeat('x', 16384).$providerSentinel.'","code":4006}',
    );
    $method = new ReflectionMethod(SchedulesDirectService::class, 'streamedProviderErrorCode');
    $exception = null;

    try {
        $method->invoke(new SchedulesDirectService, $responseFile);
    } catch (Throwable $throwable) {
        $exception = $throwable;
    } finally {
        unlink($responseFile);
    }

    expect($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('Schedules Direct streamed error response was invalid.')
        ->and($exception->getMessage())->not->toContain($providerSentinel);
});

it('reverses only the new Schedules Direct cooldown columns', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('PostgreSQL transaction and concurrent-DDL behavior is covered by SchedulesDirectPostgresMigrationTest.');
    }

    $migration = require database_path('migrations/2026_08_30_132557_add_schedules_direct_login_cooldown_to_epgs_table.php');

    $migration->down();

    expect(Schema::hasColumn('epgs', 'sd_token'))->toBeTrue()
        ->and(Schema::hasTable('schedules_direct_login_cooldowns'))->toBeFalse()
        ->and(Schema::hasTable('schedules_direct_login_cooldown_claims'))->toBeFalse()
        ->and(Schema::hasColumn('epgs', 'sd_account_identifier'))->toBeFalse()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_started_at'))->toBeFalse()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_until'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_notified_at'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('schedules_direct_login_cooldowns'))->toBeTrue()
        ->and(Schema::hasTable('schedules_direct_login_cooldown_claims'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldown_claims', 'provider_account_identifier'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldown_claims', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'account_identifier'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'started_at'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'cooldown_until'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'notified_at'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_account_identifier'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_started_at'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_until'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_notified_at'))->toBeTrue();
});

it('reruns the cooldown migration safely after partial or completed attempts', function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        $this->markTestSkipped('PostgreSQL transaction and concurrent-DDL behavior is covered by SchedulesDirectPostgresMigrationTest.');
    }

    $migration = require database_path('migrations/2026_08_30_132557_add_schedules_direct_login_cooldown_to_epgs_table.php');

    $migration->up();
    $migration->up();
    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('epgs', 'sd_token'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_account_identifier'))->toBeFalse()
        ->and(Schema::hasTable('schedules_direct_login_cooldowns'))->toBeFalse();

    Schema::create('schedules_direct_login_cooldowns', function (Blueprint $table): void {
        $table->timestamp('started_at')->nullable();
    });

    $migration->up();

    expect(Schema::hasColumn('schedules_direct_login_cooldowns', 'account_identifier'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'cooldown_until'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'notified_at'))->toBeTrue();

    $migration->down();

    Schema::table('epgs', function (Blueprint $table): void {
        $table->string('sd_account_identifier', 64)->nullable();
        $table->timestamp('sd_login_cooldown_started_at')->nullable();
    });
    Schema::create('schedules_direct_login_cooldowns', function (Blueprint $table): void {
        $table->string('account_identifier', 64)->primary();
    });

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('schedules_direct_login_cooldowns', 'account_identifier'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'started_at'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'cooldown_until'))->toBeTrue()
        ->and(Schema::hasColumn('schedules_direct_login_cooldowns', 'notified_at'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_account_identifier'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_started_at'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_until'))->toBeTrue()
        ->and(Schema::hasColumn('epgs', 'sd_login_cooldown_notified_at'))->toBeTrue();
});
