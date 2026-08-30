<?php

namespace App\Services;

use App\Exceptions\SchedulesDirectAuthenticationInProgressException;
use App\Exceptions\SchedulesDirectLoginCooldownException;
use App\Exceptions\SchedulesDirectTokenExpiredException;
use App\Facades\ProxyFacade;
use App\Models\Epg;
use App\Notifications\Notification;
use Carbon\Carbon;
use Closure;
use Exception;
use Generator;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use JsonMachine\Items;
use Throwable;

/**
 * Service to interact with the SchedulesDirect API for EPG data.
 */
class SchedulesDirectService
{
    private const API_VERSION = '20141201';

    private const BASE_URL = 'https://json.schedulesdirect.org';

    private static string $USER_AGENT = 'm3u-editor/dev';

    private static bool $FETCH_PROGRAM_ARTWORK = false; // Enable fetching program artwork

    /**
     * Error code indicating the user's account is not enabled for debug routing.
     * When received, we must disable debug mode to prevent the user from being blocked.
     */
    private const DEBUG_NOT_ENABLED_CODE = 2055;

    // At most two 45-second /token attempts leave 90 seconds for bounded persistence.
    private const AUTHENTICATION_LOCK_TTL_SECONDS = 180;

    private const AUTHENTICATION_LOCK_WAIT_SECONDS = 5;

    private const AUTHENTICATION_HTTP_TIMEOUT_SECONDS = 45;

    private const ADVISORY_LOCK_POLL_MICROSECONDS = 50000;

    private const ADVISORY_LOCK_POLL_ATTEMPTS = 100;

    private const CREDENTIAL_LOCK_ATTEMPTS = 3;

    private const AUTHENTICATION_DATABASE_ERROR = 'Schedules Direct authentication could not be completed. Please try again.';

    // Provider error objects are small; cap inspection at 4 KiB before rejecting them.
    private const STREAMED_ERROR_MAX_BYTES = 4096;

    private const LOGIN_COOLDOWNS_TABLE = 'schedules_direct_login_cooldowns';

    private const LOGIN_COOLDOWN_CLAIMS_TABLE = 'schedules_direct_login_cooldown_claims';

    private const LINEUP_ALREADY_IN_ACCOUNT_CODE = 2101;

    private const MAX_LINEUPS_CODE = 2102;

    private const LINEUP_NOT_IN_ACCOUNT_CODE = 2104;

    private const TOO_MANY_LINEUP_CHANGES_CODE = 2107;

    public const IMAGE_NOT_FOUND_CODE = 4001;

    /** Trial account download limit exceeded. */
    public const EXCEED_DOWNLOAD_LIMIT_TRIAL_CODE = 4003;

    /** Full subscriber download limit exceeded. */
    public const EXCEED_DOWNLOAD_LIMIT_CODE = 4004;

    /** Token has expired; the stored token must be discarded and re-requested once. */
    public const TOKEN_EXPIRED_CODE = 4006;

    /** Exceeded the maximum number of logins in 24 hours. */
    public const TOO_MANY_LOGINS_CODE = 4009;

    /**
     * The current EPG model being used for requests (used to check/update sd_debug)
     */
    private ?Epg $currentEpg = null;

    /**
     * @var array{owner_id: int, username: string, password: string, identifier: string, provider_identifier: string}|null
     */
    private ?array $rowlessCredentialSnapshot = null;

    // Configuration constants for performance tuning
    private const MAX_STATIONS_PER_SYNC = null;      // Limit stations for faster processing

    // SchedulesDirect allows up to 5000 stationIDs per /schedules request; 500 keeps
    // response size/time comfortably under the server's 10-minute hard cutoff while
    // cutting the number of requests per sync by 10x versus the old chunk of 50.
    private const STATIONS_PER_CHUNK = 500;

    private const SCHEDULES_TIMEOUT = 240;           // Bumped alongside the larger chunk size above

    private const DEFAULT_TIMEOUT = 60;              // Default timeout

    private const CHUNK_DELAY_MICROSECONDS = 50000;  // Reduced delay (50ms)

    // Number of sub-groups each STATIONS_PER_CHUNK-sized /schedules response is split
    // into for program-fetching/progress purposes. This is a fixed COUNT, not a fixed
    // entry size: the /schedules response has one element per (stationID, date) pair,
    // so a fixed entry-size sub-chunk would silently multiply /programs requests with
    // sd_days_to_import (e.g. size 50 becomes ~7x more requests at 7 days). A fixed
    // count instead keeps ~10 sd_progress updates (and ~10 /programs requests) per
    // batch regardless of how many days are configured.
    private const PROGRESS_STEPS_PER_BATCH = 10;

    private const MAX_RETRIES = 2;                   // Fewer retries for speed

    // SchedulesDirect allows up to 5000 programIDs per /programs request. This response
    // is streamed straight to disk (see processProgramBatchDirectly's sink()), so there's
    // no memory cost to requesting the maximum and cutting the number of requests by 5x.
    private const PROGRAMS_BATCH_SIZE = 5000;

    public function __construct()
    {
        // Set a more descriptive user agent
        self::$USER_AGENT = 'm3u-editor/'.config('dev.version');
    }

    /**
     * Build HTTP headers for SchedulesDirect API requests.
     * Includes RouteTo:debug header when sd_debug is enabled on the current EPG.
     */
    private function buildHeaders(?string $token = null): array
    {
        $headers = [
            'User-Agent' => self::$USER_AGENT,
        ];

        if ($token) {
            $headers['token'] = $token;
        }

        // Add debug routing header if sd_debug is enabled
        if ($this->currentEpg && $this->currentEpg->sd_debug) {
            $headers['RouteTo'] = 'debug';
            Log::debug('Adding RouteTo:debug header for SchedulesDirect request');
        }

        return $headers;
    }

    /**
     * Handle error code 2055 (debug not enabled) by disabling sd_debug on the EPG.
     * This prevents the user from being blocked if their account isn't enabled for debugging.
     */
    private function handleDebugNotEnabledError(): void
    {
        if ($this->currentEpg && $this->currentEpg->sd_debug) {
            Log::warning('SchedulesDirect returned code 2055 - disabling sd_debug to prevent user from being blocked', [
                'epg_id' => $this->currentEpg->id,
            ]);

            $this->currentEpg->update(['sd_debug' => false]);
            $this->currentEpg->refresh();
        }
    }

    /**
     * Set the current EPG model for tracking debug state
     */
    public function setCurrentEpg(?Epg $epg): self
    {
        $this->currentEpg = $epg;
        $this->rowlessCredentialSnapshot = null;

        return $this;
    }

    /**
     * Generator to yield station chunks for memory-efficient processing
     */
    private function getStationChunks(array $stationIds, int $chunkSize): Generator
    {
        $totalStations = count($stationIds);
        for ($i = 0; $i < $totalStations; $i += $chunkSize) {
            yield array_slice($stationIds, $i, $chunkSize);
        }
    }

    /**
     * Generator to process schedules in memory-efficient chunks
     */
    private function processScheduleChunks(string $token, array $stationIds, array $dates): Generator
    {
        foreach ($this->getStationChunks($stationIds, self::STATIONS_PER_CHUNK) as $chunkIndex => $stationChunk) {
            $chunkNumber = $chunkIndex + 1;
            $success = false;

            // Retry logic
            for ($retry = 0; $retry < self::MAX_RETRIES && ! $success; $retry++) {
                if ($retry > 0) {
                    $sleepTime = min(30, (2 ** $retry));
                    sleep($sleepTime);
                }

                $stationRequests = array_map(function ($stationId) use ($dates) {
                    return [
                        'stationID' => $stationId,
                        'date' => $dates,
                    ];
                }, $stationChunk);

                try {
                    $chunkSchedules = $this->getSchedules($token, $stationRequests);

                    if (is_array($chunkSchedules) && ! empty($chunkSchedules)) {
                        $success = true;
                        yield $chunkSchedules;

                        // Clear the chunk data immediately
                        unset($chunkSchedules, $stationRequests);
                    } else {
                        throw new Exception('Empty or invalid response received');
                    }
                } catch (SchedulesDirectLoginCooldownException $exception) {
                    throw $exception;
                } catch (SchedulesDirectTokenExpiredException $exception) {
                    throw $exception;
                } catch (Exception $e) {
                    if ($retry === self::MAX_RETRIES - 1) {
                        Log::error("Max retries exceeded for chunk {$chunkNumber}, skipping");
                    }
                }
            }

            // Delay between chunks
            usleep(self::CHUNK_DELAY_MICROSECONDS);
        }
    }

    /**
     * Authenticate with Schedules Direct using raw credentials.
     *
     * @return array{token: string, expires: int}
     */
    public function authenticate(string $username, string $password): array
    {
        return $this->withoutAuthenticationDatabaseDetails(function () use ($username, $password): array {
            $credentialSnapshot = $this->credentialSnapshot(
                (int) (auth()->id() ?? 0),
                $username,
                $password,
            );

            return $this->withAuthenticationLock($credentialSnapshot['provider_identifier'], function (Closure $assertLockOwned) use ($credentialSnapshot): array {
                $assertLockOwned();
                $this->claimCredentialRows($credentialSnapshot);
                $reusableAuthentication = $this->findReusableAuthentication($credentialSnapshot);
                $matchingEpg = $reusableAuthentication['epg'] ?? $this->findCredentialMatchingEpg($credentialSnapshot);
                $this->setCurrentEpg($matchingEpg);
                $this->rowlessCredentialSnapshot = $matchingEpg ? null : $credentialSnapshot;

                if ($reusableAuthentication) {
                    return $reusableAuthentication['authentication'];
                }

                if ($activeCooldown = $this->findActiveCooldown($credentialSnapshot)) {
                    throw $this->activeLoginCooldownException($credentialSnapshot, $activeCooldown, $matchingEpg);
                }

                $authentication = $this->requestAuthentication($credentialSnapshot, $matchingEpg, $assertLockOwned);
                $assertLockOwned();
                $this->persistAuthentication($credentialSnapshot, $authentication, storeHandoffWhenRowless: true);

                return $authentication;
            });
        });
    }

    /**
     * Authenticate using an EPG model with stored credentials.
     *
     * Authentication is single-flight per Schedules Direct account: only one
     * worker may hold the per-account lock and issue a /token request at a time.
     * Workers that arrive while a peer is authenticating wait for the lock, then
     * reuse the token the peer stored instead of logging in again.
     *
     * @return array{token: string, expires: int}
     *
     * @throws SchedulesDirectLoginCooldownException when a 4009 cooldown is active or freshly triggered
     */
    public function authenticateFromEpg(Epg $epg, bool $quietLoginCooldown = false): array
    {
        return $this->withoutAuthenticationDatabaseDetails(function () use ($epg, $quietLoginCooldown): array {
            if (! $epg->sd_username || ! $epg->sd_password) {
                throw new Exception('SchedulesDirect credentials not configured');
            }

            if ($quietLoginCooldown && $retryAt = $epg->activeSchedulesDirectLoginCooldownUntil()) {
                throw new SchedulesDirectLoginCooldownException($retryAt);
            }

            $this->setCurrentEpg($epg);
            try {
                $authentication = $this->withFreshEpgCredentialLock($epg, function (array $credentialSnapshot, Closure $assertLockOwned) use ($epg, $quietLoginCooldown): array {
                    if ($quietLoginCooldown && $activeCooldown = $this->findActiveCooldown($credentialSnapshot, quiet: true)) {
                        throw new SchedulesDirectLoginCooldownException($activeCooldown['cooldown_until']);
                    }

                    $this->claimCredentialRows($credentialSnapshot);

                    if ($retryWith = $this->refreshedCredentialSnapshotIfChanged($epg, $credentialSnapshot)) {
                        return ['retry_with' => $retryWith];
                    }

                    if ($validAuthentication = $this->findValidAuthenticationForEpg($epg, $credentialSnapshot)) {
                        $assertLockOwned();

                        return $validAuthentication;
                    }

                    if ($reusableAuthentication = $this->findReusableAuthentication($credentialSnapshot)) {
                        $authentication = $reusableAuthentication['authentication'];
                        $assertLockOwned();
                        $updated = Epg::query()
                            ->whereKey($epg->id)
                            ->where('user_id', $credentialSnapshot['owner_id'])
                            ->where('sd_account_identifier', $credentialSnapshot['identifier'])
                            ->update([
                                'sd_token' => $authentication['token'],
                                'sd_token_expires_at' => Carbon::createFromTimestampUTC($authentication['expires']),
                            ]);

                        if ($updated === 0) {
                            $epg->refresh();

                            return ['retry_with' => $this->credentialSnapshotFromEpg($epg)];
                        }

                        $epg->refresh();

                        return $authentication;
                    }

                    if ($activeCooldown = $this->findActiveCooldown($credentialSnapshot, $quietLoginCooldown)) {
                        throw $this->activeLoginCooldownException($credentialSnapshot, $activeCooldown, $epg, $quietLoginCooldown);
                    }

                    if ($retryWith = $this->refreshedCredentialSnapshotIfChanged($epg, $credentialSnapshot)) {
                        return ['retry_with' => $retryWith];
                    }

                    $authentication = $this->requestAuthentication($credentialSnapshot, $epg, $assertLockOwned, $quietLoginCooldown);

                    if ($retryWith = $this->refreshedCredentialSnapshotIfChanged($epg, $credentialSnapshot)) {
                        return ['retry_with' => $retryWith];
                    }

                    $assertLockOwned();
                    if ($this->persistAuthentication($credentialSnapshot, $authentication) === 0) {
                        $epg->refresh();

                        return ['retry_with' => $this->credentialSnapshotFromEpg($epg)];
                    }

                    return $authentication;
                });
            } catch (SchedulesDirectAuthenticationInProgressException $exception) {
                if (! $quietLoginCooldown) {
                    throw $exception;
                }

                $epg->refresh();
                $credentialSnapshot = $this->credentialSnapshotFromEpg($epg);

                if ($activeCooldown = $this->findActiveCooldown($credentialSnapshot, quiet: true)) {
                    throw new SchedulesDirectLoginCooldownException($activeCooldown['cooldown_until']);
                }

                throw $exception;
            }

            $epg->refresh();

            return $authentication;
        });
    }

    private function withAuthenticationLock(string $providerAccountIdentifier, Closure $callback): array
    {
        // This lease is not renewed. Its fixed work is at most two 45-second HTTP
        // attempts plus a bounded number of set-based database statements.
        try {
            $lock = Cache::lock(
                'schedules-direct:authentication:'.$providerAccountIdentifier,
                self::AUTHENTICATION_LOCK_TTL_SECONDS,
            );

            return $lock->block(
                self::AUTHENTICATION_LOCK_WAIT_SECONDS,
                fn (): array => $this->withProviderAccountAdvisoryLock(
                    $providerAccountIdentifier,
                    fn (?Closure $assertAdvisoryLockOwned): array => $callback(
                        $assertAdvisoryLockOwned ?? fn () => $this->assertAuthenticationLockOwned($lock),
                    ),
                ),
            );
        } catch (LockTimeoutException) {
            throw new SchedulesDirectAuthenticationInProgressException;
        }
    }

    private function withProviderAccountAdvisoryLock(string $providerAccountIdentifier, Closure $callback): mixed
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            if (app()->isProduction()) {
                throw new Exception('Schedules Direct authentication requires a database driver with advisory lock support.');
            }

            return $callback(null);
        }

        $acquired = false;
        $sessionId = null;

        if ($driver === 'pgsql') {
            $keys = $this->postgresAdvisoryLockKeys($providerAccountIdentifier);
            $deadline = microtime(true) + self::AUTHENTICATION_LOCK_WAIT_SECONDS;

            for ($attempt = 0; $attempt < self::ADVISORY_LOCK_POLL_ATTEMPTS; $attempt++) {
                $result = DB::selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired, pg_backend_pid() AS session_id', $keys);
                $acquired = in_array($result?->acquired, [true, 1, '1', 't'], true);

                if ($acquired) {
                    $sessionId = $result->session_id;

                    break;
                }

                if ($attempt < self::ADVISORY_LOCK_POLL_ATTEMPTS - 1
                    && microtime(true) + (self::ADVISORY_LOCK_POLL_MICROSECONDS / 1000000) < $deadline
                ) {
                    Sleep::usleep(self::ADVISORY_LOCK_POLL_MICROSECONDS);
                } else {
                    break;
                }
            }
        } else {
            $result = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired, CONNECTION_ID() AS session_id', [
                $providerAccountIdentifier,
                self::AUTHENTICATION_LOCK_WAIT_SECONDS,
            ]);
            $acquired = in_array($result?->acquired, [true, 1, '1'], true);
            $sessionId = $result?->session_id;
        }

        if (! $acquired || ! is_numeric($sessionId)) {
            throw new SchedulesDirectAuthenticationInProgressException;
        }

        $assertLockOwned = $this->advisoryLockOwnershipAssertion(
            $driver,
            $providerAccountIdentifier,
            (int) $sessionId,
            $keys ?? null,
        );

        try {
            return $callback($assertLockOwned);
        } finally {
            $assertLockOwned();

            if ($driver === 'pgsql') {
                $result = DB::selectOne('SELECT pg_advisory_unlock(?, ?) AS released', $keys);
            } else {
                $result = DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$providerAccountIdentifier]);
            }

            if (! in_array($result?->released, [true, 1, '1', 't'], true)) {
                throw new Exception('Schedules Direct authentication lock release could not be verified. Please try again shortly.');
            }
        }
    }

    /**
     * @param  array{int, int}|null  $postgresKeys
     */
    private function advisoryLockOwnershipAssertion(
        string $driver,
        string $providerAccountIdentifier,
        int $acquiringSessionId,
        ?array $postgresKeys,
    ): Closure {
        return function () use ($driver, $providerAccountIdentifier, $acquiringSessionId, $postgresKeys): void {
            if ($driver === 'pgsql') {
                $result = DB::selectOne(
                    <<<'SQL'
                        SELECT pg_backend_pid() AS session_id, EXISTS (
                            SELECT 1
                            FROM pg_locks
                            WHERE locktype = 'advisory'
                                AND pid = pg_backend_pid()
                                AND granted
                                AND classid::bigint = ((?::bigint + 4294967296) % 4294967296)
                                AND objid::bigint = ((?::bigint + 4294967296) % 4294967296)
                                AND objsubid = 2
                        ) AS owns_lock
                        SQL,
                    $postgresKeys,
                );
                $ownsLock = in_array($result?->owns_lock, [true, 1, '1', 't'], true);
            } else {
                $result = DB::selectOne(
                    'SELECT CONNECTION_ID() AS session_id, IS_USED_LOCK(?) AS owner_session_id',
                    [$providerAccountIdentifier],
                );
                $ownsLock = is_numeric($result?->owner_session_id)
                    && (int) $result->owner_session_id === $acquiringSessionId;
            }

            if (! is_numeric($result?->session_id)
                || (int) $result->session_id !== $acquiringSessionId
                || ! $ownsLock
            ) {
                throw new Exception('Schedules Direct authentication lock ownership was lost. Please try again shortly.');
            }
        };
    }

    /**
     * @return array{int, int}
     */
    private function postgresAdvisoryLockKeys(string $providerAccountIdentifier): array
    {
        return [
            $this->signedInt32(substr($providerAccountIdentifier, 0, 8)),
            $this->signedInt32(substr($providerAccountIdentifier, 8, 8)),
        ];
    }

    private function signedInt32(string $hex): int
    {
        $value = (int) hexdec($hex);

        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    private function assertAuthenticationLockOwned(CacheLock $lock): void
    {
        if (! $lock->isOwnedByCurrentProcess()) {
            throw new Exception('Schedules Direct authentication lease expired. Please try again shortly.');
        }
    }

    private function withoutAuthenticationDatabaseDetails(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException) {
            throw new Exception(self::AUTHENTICATION_DATABASE_ERROR);
        }
    }

    /**
     * @param  array{owner_id: int, username: string, password: string, identifier: string, provider_identifier: string}  $credentialSnapshot
     */
    private function claimCredentialRows(array $credentialSnapshot): void
    {
        if ($credentialSnapshot['owner_id'] === 0) {
            return;
        }

        $query = Epg::query()
            ->where('user_id', $credentialSnapshot['owner_id'])
            ->where('source_type', 'schedules_direct')
            ->whereNotNull('sd_username')
            ->whereRaw('LOWER(TRIM(sd_username)) = ?', [$credentialSnapshot['username']]);

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('BINARY sd_password = BINARY ?', [$credentialSnapshot['password']]);
        } else {
            $query->where('sd_password', $credentialSnapshot['password']);
        }

        $query->update(['sd_account_identifier' => $credentialSnapshot['identifier']]);
    }

    /**
     * @return array{owner_id: int, username: string, password: string, identifier: string, provider_identifier: string}
     */
    private function credentialSnapshot(int $ownerId, string $username, string $password): array
    {
        $normalizedUsername = mb_strtolower(trim($username));

        return [
            'owner_id' => $ownerId,
            'username' => $normalizedUsername,
            'password' => $password,
            'identifier' => Epg::schedulesDirectAccountIdentifier($ownerId, $normalizedUsername, $password),
            'provider_identifier' => Epg::schedulesDirectProviderAccountIdentifier($normalizedUsername),
        ];
    }

    /**
     * @return array{owner_id: int, username: string, password: string, identifier: string, provider_identifier: string}
     */
    private function credentialSnapshotFromEpg(Epg $epg): array
    {
        if (! $epg->user_id || ! $epg->sd_username || ! $epg->sd_password) {
            throw new Exception('SchedulesDirect credentials not configured');
        }

        return $this->credentialSnapshot((int) $epg->user_id, $epg->sd_username, $epg->sd_password);
    }

    private function refreshedCredentialSnapshotIfChanged(Epg $epg, array $credentialSnapshot): ?array
    {
        $epg->refresh();
        $freshCredentialSnapshot = $this->credentialSnapshotFromEpg($epg);

        return hash_equals($credentialSnapshot['identifier'], $freshCredentialSnapshot['identifier'])
            ? null
            : $freshCredentialSnapshot;
    }

    /**
     * @return array{token: string, expires: int}|null
     */
    private function findValidAuthenticationForEpg(Epg $epg, array $credentialSnapshot): ?array
    {
        return DB::transaction(function () use ($epg, $credentialSnapshot): ?array {
            $currentEpg = Epg::query()
                ->whereKey($epg->id)
                ->where('user_id', $credentialSnapshot['owner_id'])
                ->where('sd_account_identifier', $credentialSnapshot['identifier'])
                ->whereNotNull('sd_token')
                ->where('sd_token_expires_at', '>', now()->addSeconds(Epg::SCHEDULES_DIRECT_TOKEN_EXPIRY_SKEW_SECONDS))
                ->lockForUpdate()
                ->first(['sd_token', 'sd_token_expires_at']);

            if (! $currentEpg) {
                return null;
            }

            return [
                'token' => $currentEpg->sd_token,
                'expires' => $currentEpg->sd_token_expires_at->timestamp,
            ];
        });
    }

    private function withFreshEpgCredentialLock(Epg $epg, Closure $callback): array
    {
        $credentialSnapshot = $this->credentialSnapshotFromEpg($epg);

        for ($attempt = 0; $attempt < self::CREDENTIAL_LOCK_ATTEMPTS; $attempt++) {
            $result = $this->withAuthenticationLock(
                $credentialSnapshot['provider_identifier'],
                function (Closure $assertLockOwned) use ($epg, $credentialSnapshot, $callback): array {
                    $epg->refresh();
                    $freshCredentialSnapshot = $this->credentialSnapshotFromEpg($epg);

                    if (! hash_equals($credentialSnapshot['identifier'], $freshCredentialSnapshot['identifier'])) {
                        return ['retry_with' => $freshCredentialSnapshot];
                    }

                    $assertLockOwned();

                    $authentication = $callback($freshCredentialSnapshot, $assertLockOwned);

                    return isset($authentication['retry_with'])
                        ? $authentication
                        : ['authentication' => $authentication];
                },
            );

            if (! isset($result['retry_with'])) {
                return $result['authentication'];
            }

            $credentialSnapshot = $result['retry_with'];
        }

        throw new Exception('Schedules Direct credentials changed repeatedly. Please try again.');
    }

    /**
     * @return array{started_at: Carbon, cooldown_until: Carbon, notified_at: ?Carbon}|null
     */
    private function findActiveCooldown(array $credentialSnapshot, bool $quiet = false): ?array
    {
        $accountIdentifier = $credentialSnapshot['provider_identifier'];
        $canonicalCooldown = DB::table(self::LOGIN_COOLDOWNS_TABLE)
            ->where('account_identifier', $accountIdentifier)
            ->first();

        if ($canonicalCooldown) {
            if ($canonicalCooldown->started_at && $canonicalCooldown->cooldown_until && Carbon::parse($canonicalCooldown->cooldown_until)->isFuture()) {
                $cooldown = $this->canonicalCooldownData($canonicalCooldown);

                if (! $quiet) {
                    $this->mirrorLoginCooldown($credentialSnapshot, $cooldown);
                }

                return $cooldown;
            }

            return null;
        }

        if ($quiet) {
            return null;
        }

        $legacyCooldowns = $this->providerAccountEpgQuery($credentialSnapshot)
            ->where('sd_login_cooldown_until', '>', now());
        $legacyCooldownUntil = (clone $legacyCooldowns)->max('sd_login_cooldown_until');

        if (! $legacyCooldownUntil) {
            return null;
        }

        $legacyStartedAt = (clone $legacyCooldowns)->min('sd_login_cooldown_started_at') ?? now();
        $legacyNotifiedAt = (clone $legacyCooldowns)->whereNotNull('sd_login_cooldown_notified_at')->min('sd_login_cooldown_notified_at');

        DB::transaction(function () use ($accountIdentifier, $legacyStartedAt, $legacyCooldownUntil, $legacyNotifiedAt): void {
            $values = [
                'started_at' => $legacyStartedAt,
                'cooldown_until' => $legacyCooldownUntil,
                'notified_at' => $legacyNotifiedAt,
            ];
            $updated = DB::table(self::LOGIN_COOLDOWNS_TABLE)
                ->where('account_identifier', $accountIdentifier)
                ->where(function ($query): void {
                    $query->whereNull('cooldown_until')
                        ->orWhere('cooldown_until', '<=', now());
                })
                ->update($values);

            if ($updated === 0) {
                DB::table(self::LOGIN_COOLDOWNS_TABLE)->insertOrIgnore([
                    'account_identifier' => $accountIdentifier,
                    ...$values,
                ]);
            }
        });

        $canonicalCooldown = DB::table(self::LOGIN_COOLDOWNS_TABLE)
            ->where('account_identifier', $accountIdentifier)
            ->whereNotNull('started_at')
            ->where('cooldown_until', '>', now())
            ->first();

        if (! $canonicalCooldown) {
            return null;
        }

        $cooldown = $this->canonicalCooldownData($canonicalCooldown);
        $this->mirrorLoginCooldown($credentialSnapshot, $cooldown);

        return $cooldown;
    }

    /**
     * @return array{started_at: Carbon, cooldown_until: Carbon, notified_at: ?Carbon}
     */
    private function canonicalCooldownData(object $cooldown): array
    {
        return [
            'started_at' => Carbon::parse($cooldown->started_at),
            'cooldown_until' => Carbon::parse($cooldown->cooldown_until),
            'notified_at' => $cooldown->notified_at ? Carbon::parse($cooldown->notified_at) : null,
        ];
    }

    /**
     * @param  array{started_at: Carbon, cooldown_until: Carbon, notified_at: ?Carbon}  $cooldown
     */
    private function mirrorLoginCooldown(array $credentialSnapshot, array $cooldown): void
    {
        $this->providerAccountEpgQuery($credentialSnapshot)
            ->update([
                'sd_login_cooldown_started_at' => $cooldown['started_at'],
                'sd_login_cooldown_until' => $cooldown['cooldown_until'],
            ]);
    }

    private function providerAccountEpgQuery(array $credentialSnapshot): Builder
    {
        return Epg::query()
            ->where('source_type', 'schedules_direct')
            ->whereNotNull('sd_username')
            ->whereRaw('LOWER(TRIM(sd_username)) = ?', [$credentialSnapshot['username']]);
    }

    /**
     * @return array{authentication: array{token: string, expires: int}, epg: ?Epg}|null
     */
    private function findReusableAuthentication(array $credentialSnapshot): ?array
    {
        $epg = $this->findCredentialMatchingEpg($credentialSnapshot, requireValidToken: true);

        if ($epg) {
            return [
                'authentication' => [
                    'token' => $epg->sd_token,
                    'expires' => $epg->sd_token_expires_at->timestamp,
                ],
                'epg' => $epg,
            ];
        }

        $authentication = Cache::get($this->authenticationHandoffKey($credentialSnapshot));

        if (! is_array($authentication)
            || ! is_string($authentication['token'] ?? null)
            || ! is_int($authentication['expires'] ?? null)
            || $authentication['expires'] <= now()->addSeconds(Epg::SCHEDULES_DIRECT_TOKEN_EXPIRY_SKEW_SECONDS)->timestamp
        ) {
            return null;
        }

        return [
            'authentication' => $authentication,
            'epg' => null,
        ];
    }

    private function authenticationHandoffKey(array $credentialSnapshot): string
    {
        return 'schedules-direct:authentication-handoff:'.$credentialSnapshot['identifier'];
    }

    private function findCredentialMatchingEpg(array $credentialSnapshot, bool $requireValidToken = false): ?Epg
    {
        if ($credentialSnapshot['owner_id'] === 0) {
            return null;
        }

        $query = Epg::query()
            ->where('user_id', $credentialSnapshot['owner_id'])
            ->where('sd_account_identifier', $credentialSnapshot['identifier'])
            ->latest('updated_at');

        if ($requireValidToken) {
            $query
                ->whereNotNull('sd_token')
                ->where('sd_token_expires_at', '>', now()->addSeconds(Epg::SCHEDULES_DIRECT_TOKEN_EXPIRY_SKEW_SECONDS));
        }

        return $query->first();
    }

    private function requestAuthentication(
        array $credentialSnapshot,
        ?Epg $epg = null,
        ?Closure $assertLockOwned = null,
        bool $quietLoginCooldown = false,
    ): array {
        $debugRetried = false;

        do {
            $assertLockOwned?->__invoke();

            if ($reusableAuthentication = $this->findReusableAuthentication($credentialSnapshot)) {
                return $reusableAuthentication['authentication'];
            }

            if ($activeCooldown = $this->findActiveCooldown($credentialSnapshot, $quietLoginCooldown)) {
                throw $this->activeLoginCooldownException($credentialSnapshot, $activeCooldown, $epg, $quietLoginCooldown);
            }

            $assertLockOwned?->__invoke();
            $response = Http::withHeaders($this->buildHeaders())
                ->connectTimeout(10)
                ->timeout(self::AUTHENTICATION_HTTP_TIMEOUT_SECONDS)
                ->post(self::BASE_URL.'/'.self::API_VERSION.'/token', [
                    'username' => $credentialSnapshot['username'],
                    'password' => hash('sha1', $credentialSnapshot['password']),
                ]);
            $data = $response->json();
            $data = is_array($data) ? $data : [];
            $responseCode = isset($data['code']) && is_numeric($data['code']) ? (int) $data['code'] : null;

            if ($responseCode === self::DEBUG_NOT_ENABLED_CODE && ! $debugRetried && $this->currentEpg?->sd_debug) {
                $this->handleDebugNotEnabledError();
                $debugRetried = true;

                continue;
            }

            if ($responseCode === self::TOO_MANY_LOGINS_CODE || ($data['response'] ?? null) === 'TOO_MANY_LOGINS') {
                $assertLockOwned?->__invoke();

                throw $this->startLoginCooldown($credentialSnapshot, $epg, $quietLoginCooldown);
            }

            if ($response->failed() || ($responseCode !== null && $responseCode !== 0)) {
                $code = $responseCode ?? $response->status();

                throw new Exception("Schedules Direct authentication failed (code {$code}).", $code);
            }

            return $this->parseAuthenticationData($data);
        } while ($debugRetried);

        throw new Exception('Schedules Direct authentication failed.');
    }

    private function parseAuthenticationData(array $data): array
    {
        // Older compatible responses omit tokenExpires. The provider documents
        // a 24-hour token lifetime; datetime and serverTime are only clocks.
        $expires = array_key_exists('tokenExpires', $data)
            ? $data['tokenExpires']
            : now()->addDay()->timestamp;

        if (! is_string($data['token'] ?? null) || $data['token'] === '') {
            throw new Exception('Schedules Direct authentication returned an invalid token.');
        }

        if (! is_int($expires) || $expires <= now()->addSeconds(Epg::SCHEDULES_DIRECT_TOKEN_EXPIRY_SKEW_SECONDS)->timestamp) {
            throw new Exception('Schedules Direct authentication returned an invalid token expiry.');
        }

        return [
            'token' => $data['token'],
            'expires' => $expires,
        ];
    }

    private function persistAuthentication(array $credentialSnapshot, array $authentication, bool $storeHandoffWhenRowless = false): int
    {
        $updatedRows = DB::transaction(function () use ($credentialSnapshot, $authentication): int {
            DB::table(self::LOGIN_COOLDOWNS_TABLE)
                ->where('account_identifier', $credentialSnapshot['provider_identifier'])
                ->delete();
            DB::table(self::LOGIN_COOLDOWN_CLAIMS_TABLE)
                ->where('provider_account_identifier', $credentialSnapshot['provider_identifier'])
                ->delete();
            $this->providerAccountEpgQuery($credentialSnapshot)->update([
                'sd_login_cooldown_started_at' => null,
                'sd_login_cooldown_until' => null,
                'sd_login_cooldown_notified_at' => null,
            ]);

            if ($credentialSnapshot['owner_id'] === 0) {
                return 0;
            }

            return Epg::query()
                ->where('user_id', $credentialSnapshot['owner_id'])
                ->where('sd_account_identifier', $credentialSnapshot['identifier'])
                ->update([
                    'sd_login_cooldown_started_at' => null,
                    'sd_login_cooldown_until' => null,
                    'sd_login_cooldown_notified_at' => null,
                    'sd_token' => $authentication['token'],
                    'sd_token_expires_at' => Carbon::createFromTimestampUTC($authentication['expires']),
                ]);
        });

        if ($updatedRows === 0 && $storeHandoffWhenRowless) {
            Cache::put(
                $this->authenticationHandoffKey($credentialSnapshot),
                $authentication,
                Carbon::createFromTimestampUTC($authentication['expires'])
                    ->subSeconds(Epg::SCHEDULES_DIRECT_TOKEN_EXPIRY_SKEW_SECONDS),
            );
        }

        return $updatedRows;
    }

    private function startLoginCooldown(array $credentialSnapshot, ?Epg $requestingEpg, bool $quiet = false): SchedulesDirectLoginCooldownException
    {
        $accountIdentifier = $credentialSnapshot['provider_identifier'];
        $result = DB::transaction(function () use ($accountIdentifier): array {
            $activeCooldown = DB::table(self::LOGIN_COOLDOWNS_TABLE)
                ->where('account_identifier', $accountIdentifier)
                ->whereNotNull('started_at')
                ->where('cooldown_until', '>', now())
                ->first();

            if ($activeCooldown) {
                return [
                    'cooldown' => $this->canonicalCooldownData($activeCooldown),
                    'started' => false,
                ];
            }

            DB::table(self::LOGIN_COOLDOWN_CLAIMS_TABLE)
                ->where('provider_account_identifier', $accountIdentifier)
                ->delete();

            $startedAt = now();
            $endsAt = $startedAt->copy()->addDay();
            $values = [
                'started_at' => $startedAt,
                'cooldown_until' => $endsAt,
                'notified_at' => null,
            ];
            $updated = DB::table(self::LOGIN_COOLDOWNS_TABLE)
                ->where('account_identifier', $accountIdentifier)
                ->where(function ($query): void {
                    $query->whereNull('cooldown_until')
                        ->orWhere('cooldown_until', '<=', now());
                })
                ->update($values);

            if ($updated === 0) {
                DB::table(self::LOGIN_COOLDOWNS_TABLE)->insertOrIgnore([
                    'account_identifier' => $accountIdentifier,
                    ...$values,
                ]);
            }

            $canonicalCooldown = DB::table(self::LOGIN_COOLDOWNS_TABLE)
                ->where('account_identifier', $accountIdentifier)
                ->first();

            return [
                'cooldown' => $this->canonicalCooldownData($canonicalCooldown),
                'started' => true,
            ];
        });
        $cooldown = $result['cooldown'];

        if ($quiet) {
            return new SchedulesDirectLoginCooldownException($cooldown['cooldown_until']);
        }

        if ($result['started']) {
            $this->providerAccountEpgQuery($credentialSnapshot)
                ->update(['sd_login_cooldown_notified_at' => null]);
        }

        $this->mirrorLoginCooldown($credentialSnapshot, $cooldown);

        $this->sendLoginCooldownNotification($credentialSnapshot, $cooldown['cooldown_until'], $requestingEpg);

        return new SchedulesDirectLoginCooldownException($cooldown['cooldown_until']);
    }

    /**
     * @param  array{started_at: Carbon, cooldown_until: Carbon, notified_at: ?Carbon}  $activeCooldown
     */
    private function activeLoginCooldownException(array $credentialSnapshot, array $activeCooldown, ?Epg $requestingEpg, bool $quiet = false): SchedulesDirectLoginCooldownException
    {
        if (! $quiet) {
            $this->sendLoginCooldownNotification($credentialSnapshot, $activeCooldown['cooldown_until'], $requestingEpg);
        }

        return new SchedulesDirectLoginCooldownException($activeCooldown['cooldown_until']);
    }

    private function sendLoginCooldownNotification(array $credentialSnapshot, Carbon $cooldownUntil, ?Epg $requestingEpg): void
    {
        $accountIdentifier = $credentialSnapshot['provider_identifier'];
        $notificationEpg = $requestingEpg && $credentialSnapshot['owner_id'] !== 0
            ? $this->providerAccountEpgQuery($credentialSnapshot)
                ->where('user_id', $credentialSnapshot['owner_id'])
                ->find($requestingEpg->id)
            : null;
        $user = $notificationEpg?->user()->first();

        if (! $user && auth()->id() === $credentialSnapshot['owner_id']) {
            $user = auth()->user();
        }

        if (! $user) {
            return;
        }

        try {
            DB::transaction(function () use ($accountIdentifier, $credentialSnapshot, $cooldownUntil, $user): void {
                $claimedAt = now();
                $notificationClaimed = DB::table(self::LOGIN_COOLDOWN_CLAIMS_TABLE)->insertOrIgnore([
                    'provider_account_identifier' => $accountIdentifier,
                    'user_id' => $user->getKey(),
                    'notified_at' => $claimedAt,
                ]);

                if ($notificationClaimed === 0) {
                    return;
                }

                $this->providerAccountEpgQuery($credentialSnapshot)
                    ->where('user_id', $credentialSnapshot['owner_id'])
                    ->where('sd_login_cooldown_until', $cooldownUntil)
                    ->update(['sd_login_cooldown_notified_at' => $claimedAt]);

                $user->notifyNow(
                    Notification::make()
                        ->warning()
                        ->title('Schedules Direct login limit reached')
                        ->body('Authentication is paused until '.$cooldownUntil->toIso8601String().'.')
                        ->toDatabase(),
                );
            });
        } catch (Throwable $throwable) {
            Log::warning('Failed to send Schedules Direct login cooldown notification', [
                'error_class' => $throwable::class,
            ]);
        }
    }

    /**
     * Get server status
     */
    public function getStatus(string $token): array
    {
        $response = $this->makeRequest('GET', '/status', [], $token);

        return $response->json();
    }

    /**
     * Return the maximum number of lineups allowed for this account from the /status endpoint.
     * Falls back to 4 (the default for most accounts) if the value cannot be determined.
     */
    public function getAccountMaxLineups(string $token): int
    {
        try {
            $status = $this->getStatus($token);

            return (int) ($status['account']['maxLineups'] ?? 4);
        } catch (SchedulesDirectLoginCooldownException $exception) {
            throw $exception;
        } catch (Exception) {
            return 4;
        }
    }

    /**
     * Get available countries
     * Results are cached for 5 minutes
     */
    public function getCountries(): array
    {
        return Cache::remember('schedules_direct_countries', 300, function () {
            $response = Http::withHeaders([
                'User-Agent' => self::$USER_AGENT,
            ])->get(self::BASE_URL.'/'.self::API_VERSION.'/available/countries');

            if ($response->failed()) {
                throw new Exception('Failed to get countries from SchedulesDirect');
            }

            return $response->json();
        });
    }

    /**
     * Get headends for a postal code
     */
    public function getHeadends(string $token, string $country, string $postalCode): array
    {
        $response = $this->makeRequest('GET', '/headends', [
            'country' => $country,
            'postalcode' => $postalCode,
        ], $token);

        return $response->json();
    }

    /**
     * Preview a lineup
     */
    public function previewLineup(string $token, string $lineupId): array
    {
        $response = $this->makeRequest('GET', "/lineups/preview/{$lineupId}", [], $token);

        return $response->json();
    }

    /**
     * Get lineups currently added to the account
     */
    public function getAccountLineups(string $token): array
    {
        $response = $this->makeRequest('GET', '/lineups', [], $token);

        return $response->json();
    }

    /**
     * Add a lineup to the account
     */
    public function addLineup(string $token, string $lineupId): array
    {
        try {
            $response = $this->makeRequest('PUT', "/lineups/{$lineupId}", [], $token);

            return $response->json();
        } catch (Exception $e) {
            match ($e->getCode()) {
                self::LINEUP_ALREADY_IN_ACCOUNT_CODE => null, // idempotent — already added
                self::MAX_LINEUPS_CODE => throw new Exception(
                    'Your SchedulesDirect account has reached the maximum number of allowed lineups. Remove an existing lineup before adding a new one.',
                    self::MAX_LINEUPS_CODE,
                    $e
                ),
                self::TOO_MANY_LINEUP_CHANGES_CODE => throw new Exception(
                    'You have exceeded the daily limit of 6 lineup changes on your SchedulesDirect account. Please try again tomorrow.',
                    self::TOO_MANY_LINEUP_CHANGES_CODE,
                    $e
                ),
                default => throw $e,
            };

            return ['code' => 0, 'message' => 'Lineup already in account'];
        }
    }

    /**
     * Remove a lineup from the account
     */
    public function removeLineup(string $token, string $lineupId): array
    {
        try {
            $response = $this->makeRequest('DELETE', "/lineups/{$lineupId}", [], $token);

            return $response->json();
        } catch (Exception $e) {
            match ($e->getCode()) {
                self::LINEUP_NOT_IN_ACCOUNT_CODE => null, // idempotent — already removed
                self::TOO_MANY_LINEUP_CHANGES_CODE => throw new Exception(
                    'You have exceeded the daily limit of 6 lineup changes on your SchedulesDirect account. Please try again tomorrow.',
                    self::TOO_MANY_LINEUP_CHANGES_CODE,
                    $e
                ),
                default => throw $e,
            };

            return ['code' => 0, 'message' => 'Lineup not in account'];
        }
    }

    /**
     * Return all account lineups as a keyed options array suitable for Filament Select fields.
     * Authenticates if the EPG token is missing or expired.
     *
     * @return array<string, string> lineup_id => "Name (transport)"
     */
    public function getAccountLineupsAsOptions(Epg $epg): array
    {
        $this->setCurrentEpg($epg);

        if (! $epg->hasValidSchedulesDirectToken()) {
            $this->authenticateFromEpg($epg);
            $epg->refresh();
        }

        $userLineups = $this->getUserLineups($epg->sd_token);

        return collect($userLineups['lineups'] ?? [])
            ->mapWithKeys(fn ($lineup) => [$lineup['lineup'] => "{$lineup['name']} — {$lineup['lineup']} ({$lineup['transport']})"])
            ->all();
    }

    /**
     * Remove a specific lineup from the EPG's SD account, re-authenticating if needed.
     */
    public function removeLineupFromEpg(Epg $epg, string $lineupId): void
    {
        $this->setCurrentEpg($epg);

        if (! $epg->hasValidSchedulesDirectToken()) {
            $this->authenticateFromEpg($epg);
            $epg->refresh();
        }

        $this->removeLineup($epg->sd_token, $lineupId);
    }

    /**
     * Remove the lineup that is currently configured on the EPG from the SD account.
     * No-op if no lineup is configured.
     */
    public function removeConfiguredLineup(Epg $epg): void
    {
        if (! $epg->hasSchedulesDirectLineup()) {
            return;
        }

        $this->removeLineupFromEpg($epg, $epg->sd_lineup_id);
    }

    /**
     * Get lineup details including stations
     */
    public function getLineup(string $token, string $lineupId): array
    {
        $response = $this->makeRequest('GET', "/lineups/{$lineupId}", [], $token);

        return $response->json();
    }

    /**
     * Get user's lineups
     */
    public function getUserLineups(string $token): array
    {
        $response = $this->makeRequest('GET', '/lineups', [], $token);

        return $response->json();
    }

    /**
     * Get schedules for station IDs
     */
    public function getSchedules(string $token, array $stationRequests): array
    {
        $response = $this->makeRequest('POST', '/schedules', $stationRequests, $token);

        return $response->json();
    }

    /**
     * Get program information
     */
    public function getPrograms(string $token, array $programIds): array
    {
        $response = $this->makeRequest('POST', '/programs', $programIds, $token);

        return $response->json();
    }

    public function getImage(Epg $epg, string $imageHash, bool $quietLoginCooldown = false): Response
    {
        $this->setCurrentEpg($epg);

        if (! $epg->hasValidSchedulesDirectToken()) {
            $this->authenticateFromEpg($epg, $quietLoginCooldown);
            $epg->refresh();
        }

        return $this->makeRequest(
            'GET',
            '/image/'.$imageHash,
            token: $epg->sd_token,
            throwOnFailure: false,
            quietLoginCooldown: $quietLoginCooldown,
        );
    }

    /**
     * Get artwork for programs
     *
     * Based on testing, the /metadata/programs endpoint returns error 1008 "INCORRECT_REQUEST"
     * for all tested formats. The regular /programs endpoint shows hasImageArtwork=true,
     * indicating artwork is available, but accessed differently.
     *
     * For now, this returns empty array but could be enhanced to:
     * 1. Check for artwork URLs embedded in program responses
     * 2. Try alternative API endpoints for metadata
     * 3. Use program flags to determine if artwork exists
     */
    public function getProgramArtwork(string $token, array $programIds, ?string $epgUuid = null): array
    {
        if (empty($programIds)) {
            return [];
        }

        if ($this->currentEpg?->hasValidSchedulesDirectToken()) {
            $token = $this->currentEpg->sd_token;
        }

        // SchedulesDirect has a limit of 500 program IDs per request
        $maxBatchSize = 500;
        $allArtwork = [];

        try {
            Log::debug('Fetching program artwork from SchedulesDirect', [
                'program_count' => count($programIds),
                'batches_needed' => ceil(count($programIds) / $maxBatchSize),
            ]);

            // Process in batches of 500 or fewer
            $batches = array_chunk($programIds, $maxBatchSize);

            foreach ($batches as $batchIndex => $batch) {
                Log::debug('Processing artwork batch', [
                    'batch' => $batchIndex + 1,
                    'batch_size' => count($batch),
                ]);

                $debugRetried = false;
                $tokenRetried = false;

                while (true) {
                    $response = Http::withHeaders($this->buildHeaders($token))
                        ->connectTimeout(10)
                        ->timeout(30)
                        ->post(self::BASE_URL.'/'.self::API_VERSION.'/metadata/programs/', $batch);
                    $artworkData = $response->json();
                    $artworkData = is_array($artworkData) ? $artworkData : [];
                    $responseCode = isset($artworkData['code']) && is_numeric($artworkData['code'])
                        ? (int) $artworkData['code']
                        : null;

                    if ($responseCode === self::DEBUG_NOT_ENABLED_CODE && ! $debugRetried) {
                        $this->handleDebugNotEnabledError();
                        $debugRetried = true;

                        continue;
                    }

                    if ($responseCode === self::TOKEN_EXPIRED_CODE) {
                        if (! $tokenRetried && $replacementToken = $this->recoverCurrentEpgToken($token)) {
                            $token = $replacementToken;
                            $tokenRetried = true;

                            continue;
                        }

                        $this->invalidateCurrentEpgAuthentication($token);

                        throw new SchedulesDirectTokenExpiredException('Schedules Direct token remained expired after one recovery attempt.');
                    }

                    break;
                }

                if ($response->successful() && ($responseCode === null || $responseCode === 0)) {
                    foreach ($artworkData as $programArtwork) {
                        $programId = $programArtwork['programID'] ?? null;
                        $artworkItems = $programArtwork['data'] ?? [];

                        if ($programId && ! empty($artworkItems)) {
                            // Group and process all artwork types, not just the "best" one
                            $processedArtwork = $this->selectBestArtwork($artworkItems, $epgUuid);

                            if (! empty($processedArtwork)) {
                                $allArtwork[$programId] = $processedArtwork;
                            }
                        }
                    }
                } else {
                    Log::error('Failed to fetch program artwork batch', [
                        'batch' => $batchIndex + 1,
                        'status' => $response->status(),
                        'code' => $responseCode,
                    ]);
                }

                // Add small delay between batches to be respectful to the API
                if ($batchIndex < count($batches) - 1) {
                    usleep(100000); // 100ms delay
                }
            }

            Log::debug('Successfully fetched program artwork', [
                'programs_with_artwork' => count($allArtwork),
                'total_programs' => count($programIds),
                'batches_processed' => count($batches),
            ]);

            return $allArtwork;
        } catch (SchedulesDirectLoginCooldownException $exception) {
            throw $exception;
        } catch (SchedulesDirectTokenExpiredException $exception) {
            throw $exception;
        } catch (Exception $e) {
            Log::error('Exception while fetching program artwork', [
                'error' => $e->getMessage(),
                'program_count' => count($programIds),
            ]);

            return [];
        }
    }

    /**
     * Select only the best 1-2 images per type to avoid XMLTV bloat
     */
    private function selectBestArtwork(array $artworkItems, ?string $epgUuid = null): array
    {
        $selectedArtwork = [];
        $typeGroups = [];

        // Group artwork by type
        foreach ($artworkItems as $artwork) {
            if (empty($artwork['uri'])) {
                continue;
            }

            $xmltvType = $this->mapSchedulesDirectCategoryToXMLTV($artwork['category'] ?? '');
            if (empty($xmltvType)) {
                continue;
            } // Skip unmappable types

            $typeGroups[$xmltvType][] = $artwork;
        }

        // Select the best 1-2 images per type
        foreach ($typeGroups as $type => $artworks) {
            // Sort by quality (prefer higher resolution and better tiers)
            usort($artworks, function ($a, $b) {
                $scoreA = $this->calculateArtworkScore($a);
                $scoreB = $this->calculateArtworkScore($b);

                return $scoreB <=> $scoreA; // Descending order (highest score first)
            });

            // Take only the best 1-2 images per type
            $limit = ($type === 'poster') ? 2 : 1; // Allow 2 posters, 1 of other types
            $selectedFromType = array_slice($artworks, 0, $limit);

            foreach ($selectedFromType as $artwork) {
                $imageUrl = $this->buildImageUrl($artwork['uri'], $epgUuid);

                $artworkInfo = [
                    'url' => $imageUrl,
                    'type' => $type,
                    'width' => $artwork['width'] ?? 0,
                    'height' => $artwork['height'] ?? 0,
                    'orient' => $this->determineOrientation($artwork['width'] ?? 0, $artwork['height'] ?? 0),
                    'size' => $this->mapImageSize($artwork['width'] ?? 0, $artwork['height'] ?? 0),
                    'category' => $artwork['category'] ?? '',
                    'tier' => $artwork['tier'] ?? '',
                ];

                $selectedArtwork[] = $artworkInfo;
            }
        }

        Log::debug('Artwork selection completed', [
            'original_count' => count($artworkItems),
            'selected_count' => count($selectedArtwork),
            'types_found' => array_keys($typeGroups),
        ]);

        return $selectedArtwork;
    }

    /**
     * Calculate a quality score for artwork to prioritize the best images
     */
    private function calculateArtworkScore(array $artwork): int
    {
        $score = 0;

        // Resolution scoring (higher resolution = better)
        $width = $artwork['width'] ?? 0;
        $height = $artwork['height'] ?? 0;
        $pixels = $width * $height;

        if ($pixels >= 1000000) {
            $score += 100;
        } // 1MP+
        elseif ($pixels >= 500000) {
            $score += 80;
        }  // 500K+
        elseif ($pixels >= 250000) {
            $score += 60;
        }  // 250K+
        elseif ($pixels >= 100000) {
            $score += 40;
        }  // 100K+
        else {
            $score += 20;
        } // Small images

        // Tier scoring (Episode > Season > Series)
        $tier = strtolower($artwork['tier'] ?? '');
        switch ($tier) {
            case 'episode':
                $score += 50;
                break;
            case 'season':
                $score += 40;
                break;
            case 'series':
                $score += 30;
                break;
            default:
                $score += 20;
                break;
        }

        // Category scoring (prefer iconic/poster over banners)
        $category = strtolower($artwork['category'] ?? '');
        if (str_contains($category, 'iconic')) {
            $score += 30;
        } elseif (str_contains($category, 'poster')) {
            $score += 25;
        } elseif (str_contains($category, 'banner-l1')) {
            $score += 20;
        } elseif (str_contains($category, 'banner')) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Build the complete image URL from the URI
     */
    private function buildImageUrl(string $uri, ?string $epgUuid = null): string
    {
        // If URI is already a complete URL (starts with https://), return as-is
        if (str_starts_with($uri, 'https://')) {
            return $uri;
        }

        // If we have an EPG UUID, use the proxy URL
        if ($epgUuid) {
            return ProxyFacade::getBaseUrl().'/schedules-direct/'.$epgUuid.'/image/'.$uri;
        }

        // Fallback to direct URL (will require authentication)
        return self::BASE_URL.'/'.self::API_VERSION.'/image/'.$uri;
    }

    /**
     * Map SchedulesDirect artwork categories to XMLTV image types
     */
    private function mapSchedulesDirectCategoryToXMLTV(string $category): string
    {
        return match (strtolower($category)) {
            // Main poster/iconic images
            'iconic' => 'poster',
            'poster art', 'box art' => 'poster',

            // Banner images (usually landscape) - map to backdrop
            'banner', 'banner-l1', 'banner-l2', 'banner-l3', 'banner-lo', 'banner-lot' => 'backdrop',

            // Still images from shows/movies
            'scene still', 'photo', 'still' => 'still',

            // People images
            'cast ensemble', 'cast in character' => 'character',
            'photo-headshot' => 'person',

            // Logo/branding - not typically used in XMLTV image tags, skip
            'logo', 'staple' => '', // Return empty to skip

            default => 'poster' // Default to poster for unrecognized categories
        };
    }

    /**
     * Determine image orientation from dimensions
     */
    private function determineOrientation(int $width, int $height): string
    {
        if ($width == 0 || $height == 0) {
            return 'P'; // Default to portrait
        }

        return $width > $height ? 'L' : 'P'; // Landscape or Portrait
    }

    /**
     * Map image dimensions to XMLTV size (1=small, 2=medium, 3=large)
     */
    private function mapImageSize(int $width, int $height): string
    {
        $totalPixels = $width * $height;

        if ($totalPixels >= 1000000) { // >= ~1000x1000
            return '3'; // Large
        } elseif ($totalPixels >= 250000) { // >= ~500x500
            return '2'; // Medium
        } else {
            return '1'; // Small
        }
    }

    /**
     * Extract station artwork directly from lineup data
     * Station logos are included in the lineup response, not from a separate API
     */
    private function extractStationArtworkFromLineup(array $lineupData): array
    {
        $stationArtworkCache = [];

        try {
            if (! empty($lineupData['stations'])) {
                Log::debug('Extracting station artwork from lineup', ['station_count' => count($lineupData['stations'])]);

                foreach ($lineupData['stations'] as $station) {
                    $stationId = $station['stationID'] ?? null;
                    if ($stationId && ! empty($station['stationLogo'])) {
                        foreach ($station['stationLogo'] as $logo) {
                            if (! empty($logo['URL'])) {
                                $stationArtworkCache[$stationId] = $logo['URL'];
                                break; // Use first available logo
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('Failed to extract station artwork from lineup', ['error' => $e->getMessage()]);
        }

        Log::debug('Station artwork cache built', ['stations' => count($stationArtworkCache)]);

        return $stationArtworkCache;
    }

    /**
     * Extract artwork URL directly from program data
     * This looks for artwork URLs embedded in the program response itself
     */
    private function extractArtworkFromProgram($program): ?string
    {
        // Check if program has artwork flags
        $hasArtwork = $program->hasImageArtwork ?? false;
        $hasEpisodeArtwork = $program->hasEpisodeArtwork ?? false;
        $hasSeriesArtwork = $program->hasSeriesArtwork ?? false;
        $hasSeasonArtwork = $program->hasSeasonArtwork ?? false;

        if (! $hasArtwork && ! $hasEpisodeArtwork && ! $hasSeriesArtwork && ! $hasSeasonArtwork) {
            return null;
        }

        // For now, we'll return null since we need to implement the metadata API correctly
        // The artwork URLs are not included in the regular program data
        return null;
    }

    /**
     * Fetch SchedulesDirect EPG data and update the EPG record.
     *
     * Authenticated requests recover from TOKEN_EXPIRED (4006) once in place.
     * A repeated 4006 terminates the sync with a typed exception.
     *
     * @throws SchedulesDirectLoginCooldownException when a 4009 login-limit cooldown is active
     */
    public function syncEpgData(Epg $epg): void
    {
        // Set the current EPG for debug header tracking
        $this->setCurrentEpg($epg);

        Log::debug('Starting SchedulesDirect sync', [
            'epg_id' => $epg->id,
            'chunk_size' => self::STATIONS_PER_CHUNK,
            'sd_debug' => $epg->sd_debug,
        ]);

        $this->performEpgSync($epg);
    }

    private function performEpgSync(Epg $epg): void
    {
        try {
            // Validate token or re-authenticate
            if (! $epg->hasValidSchedulesDirectToken()) {
                $this->authenticateFromEpg($epg);
            }

            // Get lineup data
            if (! $epg->hasSchedulesDirectLineup()) {
                throw new Exception('No lineup configured for SchedulesDirect EPG');
            }

            // Set the metadata fetching flag
            self::$FETCH_PROGRAM_ARTWORK = $epg->sd_metadata['enabled'] ?? false;

            // Reset EPG SD sync status
            $epg->update([
                'sd_errors' => null,
                'sd_last_sync' => null,
                'sd_progress' => 0,
            ]);

            // Check if lineup is already in account; add it if not
            try {
                $lineupData = $this->getLineup($epg->sd_token, $epg->sd_lineup_id);
            } catch (Exception $e) {
                // 4003/4004 = lineup not found/not subscribed
                if ($e->getCode() === self::LINEUP_NOT_IN_ACCOUNT_CODE
                    || str_contains($e->getMessage(), 'not in account')
                    || str_contains($e->getMessage(), 'not subscribed')
                ) {
                    Log::debug("Adding lineup {$epg->sd_lineup_id} to SchedulesDirect account", ['epg_id' => $epg->id]);
                    $this->addLineup($epg->sd_token, $epg->sd_lineup_id);
                    $lineupData = $this->getLineup($epg->sd_token, $epg->sd_lineup_id);
                } else {
                    throw $e;
                }
            }

            // Refresh station IDs from the current lineup on every sync so stations
            // Schedules Direct removes/remaps server-side don't linger and get
            // requested after they're no longer valid (causes SD to block the app).
            $stationIds = array_column($lineupData['map'], 'stationID');
            $epg->update(['sd_station_ids' => $stationIds]);

            // Use limited stations for faster processing
            $stationIds = self::MAX_STATIONS_PER_SYNC
                ? array_slice($stationIds, 0, self::MAX_STATIONS_PER_SYNC)
                : $stationIds;

            Log::debug('Starting SchedulesDirect sync', [
                'epg_id' => $epg->id,
                'station_count' => count($stationIds),
                'chunk_size' => self::STATIONS_PER_CHUNK,
            ]);

            // Generate dates
            $dates = [];
            for ($i = 0; $i < $epg->sd_days_to_import; $i++) {
                $dates[] = Carbon::now()->addDays($i)->format('Y-m-d');
            }

            // Stream process schedules and build XMLTV on the fly
            $xmlFilePath = $this->streamProcessToXMLTV($epg, $lineupData, $stationIds, $dates);

            // Update EPG record
            $epg->update([
                'sd_last_sync' => now(),
                'sd_errors' => null,
                'sd_progress' => 100,
            ]);
            Log::debug('Successfully completed SchedulesDirect sync', [
                'epg_id' => $epg->id,
                'stations_processed' => count($stationIds),
                'file_path' => $xmlFilePath,
            ]);
        } catch (SchedulesDirectTokenExpiredException $e) {
            throw $e;
        } catch (SchedulesDirectLoginCooldownException $e) {
            throw $e;
        } catch (Exception $e) {
            $errors = $epg->sd_errors ?? [];
            $errors[] = [
                'timestamp' => now()->toISOString(),
                'message' => $e->getMessage(),
            ];

            $epg->update(['sd_errors' => $errors]);
            Log::error('Failed to sync SchedulesDirect EPG data', [
                'epg_id' => $epg->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Stream process data directly to XMLTV file to minimize memory usage
     * Optimized version that processes schedules and programs in a single pass
     */
    private function streamProcessToXMLTV(
        Epg $epg,
        array $lineupData,
        array $stationIds,
        array $dates
    ): string {
        // Prepare file path
        $filePath = Storage::disk('local')->path($epg->file_path);

        // Remove old file if exists
        if (Storage::disk('local')->exists($epg->file_path)) {
            Storage::disk('local')->delete($epg->file_path);
        }

        // Ensure directory exists
        if (! Storage::disk('local')->exists($epg->folder_path)) {
            Storage::disk('local')->makeDirectory($epg->folder_path);
        }

        // Open file for writing
        $file = fopen($filePath, 'w');
        if (! $file) {
            throw new Exception("Cannot open file for writing: {$filePath}");
        }
        try {
            // Extract station artwork from lineup data (logos are included in lineup response)
            Log::debug('Extracting station artwork from lineup data');
            $stationArtworkCache = $this->extractStationArtworkFromLineup($lineupData);

            // Write XML header and channels with artwork
            $this->writeXMLTVHeader($file, $lineupData, ['stations' => $stationArtworkCache]);

            // Use optimized single-pass processing - program artwork will be fetched during processing
            $this->runStreamSchedulesToXMLTV($file, $epg, $stationIds, $dates, ['stations' => $stationArtworkCache]);

            // Write XML footer
            fwrite($file, "</tv>\n");
        } catch (Exception $e) {
            Log::error('Failed to stream process to XMLTV', [
                'epg_id' => $epg->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        } finally {
            fclose($file);
            $epg->update(['sd_progress' => 100]);
        }

        return $filePath;
    }

    /**
     * Write XMLTV header and channel information
     */
    private function writeXMLTVHeader($file, array $lineupData, array $artworkCache = []): void
    {
        fwrite($file, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        fwrite($file, "<tv generator-info-name=\"m3u editor SchedulesDirect Integration\" generator-info-url=\"https://github.com/sparkison/m3u-editor\">\n");

        // Write channels
        $stationsById = [];
        foreach ($lineupData['stations'] as $station) {
            $stationsById[$station['stationID']] = $station;
        }

        foreach ($lineupData['map'] as $mapping) {
            $station = $stationsById[$mapping['stationID']] ?? null;
            if (! $station) {
                continue;
            }

            fwrite($file, "  <channel id=\"{$mapping['stationID']}\">\n");

            // Display names - prefer name, then callsign, then channel number
            if (! empty($station['name'])) {
                $name = htmlspecialchars($station['name']);
                fwrite($file, "    <display-name>{$name}</display-name>\n");
            }

            if (! empty($station['callsign'])) {
                $callsign = htmlspecialchars($station['callsign']);
                fwrite($file, "    <display-name>{$callsign}</display-name>\n");
            }

            // Channel number and callsign
            $channelNumber = htmlspecialchars($mapping['channel'] ?? $station['callsign']);
            fwrite($file, "    <display-name>{$channelNumber}</display-name>\n");

            // Add channel icon if available
            if (isset($artworkCache['stations'][$mapping['stationID']])) {
                $iconUrl = htmlspecialchars($artworkCache['stations'][$mapping['stationID']]);
                fwrite($file, "    <icon src=\"{$iconUrl}\" />\n");
            }

            fwrite($file, "  </channel>\n");
        }
    }

    /**
     * Simplified single-pass schedule and program processing - no indexing, direct API calls
     */
    private function runStreamSchedulesToXMLTV(
        $file,
        Epg $epg,
        array $stationIds,
        array $dates,
        array $artworkCache = []
    ): void {
        @ini_set('max_execution_time', 0);

        $totalStations = count($stationIds);
        $totalNetworkChunks = (int) ceil($totalStations / self::STATIONS_PER_CHUNK);
        $totalProgressSteps = $totalNetworkChunks * self::PROGRESS_STEPS_PER_BATCH;

        Log::debug('Starting simplified EPG processing', [
            'epg_id' => $epg->id,
            'total_stations' => $totalStations,
            'schedules_request_chunk_size' => self::STATIONS_PER_CHUNK,
            'progress_steps_per_batch' => self::PROGRESS_STEPS_PER_BATCH,
            'total_progress_steps' => $totalProgressSteps,
        ]);

        // Process schedules and programs in a single streaming pass. Schedules are
        // fetched from SchedulesDirect in large STATIONS_PER_CHUNK batches (fewer
        // requests), then each batch is split into a fixed COUNT of sub-groups
        // (PROGRESS_STEPS_PER_BATCH) - not a fixed entry size - so /programs requests
        // and sd_progress updates stay bounded regardless of sd_days_to_import.
        $progressStep = 0;
        $totalProgramsWritten = 0;
        foreach ($this->processScheduleChunks($epg->sd_token, $stationIds, $dates) as $scheduleChunk) {
            $subChunkSize = max(1, (int) ceil(count($scheduleChunk) / self::PROGRESS_STEPS_PER_BATCH));
            foreach (array_chunk($scheduleChunk, $subChunkSize) as $scheduleSubChunk) {
                $progressStep++;
                Log::debug('Processing schedule sub-chunk', [
                    'step' => $progressStep,
                    'total_steps' => $totalProgressSteps,
                ]);

                // Stream through the sub-chunk and collect unique program IDs using file-based deduplication
                $tempProgramIdFile = tempnam(sys_get_temp_dir(), 'epg_programs_chunk_'.$progressStep.'_');
                $programIdHandle = fopen($tempProgramIdFile, 'w');
                $seenProgramIds = []; // Small lookup table for deduplication
                $scheduleCount = 0;
                $programCount = 0;
                foreach ($scheduleSubChunk as $schedule) {
                    $scheduleCount++;
                    foreach ($schedule['programs'] ?? [] as $program) {
                        $programId = $program['programID'];
                        // Use array key existence check for O(1) deduplication
                        if (! isset($seenProgramIds[$programId])) {
                            $seenProgramIds[$programId] = true;
                            fwrite($programIdHandle, $programId."\n");
                            $programCount++;
                        }
                    }
                }

                // Close the program ID file handle
                fclose($programIdHandle);
                Log::debug('Collected program IDs from schedule sub-chunk', [
                    'step' => $progressStep,
                    'schedules_in_sub_chunk' => $scheduleCount,
                    'unique_program_ids' => $programCount,
                ]);

                // Fetch programs for this sub-chunk only using streaming batches
                if ($programCount > 0) {
                    Log::debug('Fetching programs for sub-chunk', [
                        'step' => $progressStep,
                        'program_count' => $programCount,
                    ]);
                    try {
                        // Stream process programs directly without creating lookup arrays
                        $chunkProgramsWritten = 0;
                        $this->streamProcessProgramsDirectly($tempProgramIdFile, $epg->sd_token, $progressStep, $scheduleSubChunk, $file, $chunkProgramsWritten, $artworkCache, $epg);
                        $totalProgramsWritten += $chunkProgramsWritten;
                        Log::debug('Sub-chunk completed', [
                            'step' => $progressStep,
                            'programs_written' => $chunkProgramsWritten,
                            'total_programs_written' => $totalProgramsWritten,
                        ]);
                    } catch (SchedulesDirectLoginCooldownException $exception) {
                        throw $exception;
                    } catch (SchedulesDirectTokenExpiredException $exception) {
                        throw $exception;
                    } catch (Exception $e) {
                        Log::error('Error processing sub-chunk programs', [
                            'step' => $progressStep,
                            'error' => $e->getMessage(),
                        ]);

                        continue;
                    } finally {
                        // Clean up temporary file
                        if (isset($tempProgramIdFile) && file_exists($tempProgramIdFile)) {
                            unlink($tempProgramIdFile);
                        }
                    }
                }

                // Update progress
                $progress = min(100, (int) (($progressStep / $totalProgressSteps) * 100));
                $epg->update(['sd_progress' => $progress]);

                // Clear sub-chunk from memory
                unset($scheduleSubChunk, $seenProgramIds);

                // Force garbage collection every few sub-chunks
                if ($progressStep % 2 === 0) {
                    gc_collect_cycles();
                }
            }

            unset($scheduleChunk);
        }
        Log::debug('EPG processing completed', [
            'total_programs_written' => $totalProgramsWritten,
            'steps_processed' => $progressStep,
        ]);
    }

    /**
     * Stream process programs directly without creating lookup arrays - pure streaming approach
     */
    private function streamProcessProgramsDirectly(string $programIdFile, string $token, int $chunkIndex, array $scheduleChunk, $file, int &$programsWritten, array $artworkCache = [], ?Epg $epg = null): void
    {
        $handle = fopen($programIdFile, 'r');
        if (! $handle) {
            throw new Exception("Cannot open program ID file: {$programIdFile}");
        }

        $batch = [];
        $batchIndex = 0;
        try {
            // Stream through program IDs and batch them
            while (($line = fgets($handle)) !== false) {
                $programId = trim($line);
                if (! empty($programId)) {
                    $batch[] = $programId;

                    // When we reach batch size, process the programs immediately
                    if (count($batch) >= self::PROGRAMS_BATCH_SIZE) {
                        $this->processProgramBatchDirectly($batch, $batchIndex, $token, $chunkIndex, $scheduleChunk, $file, $programsWritten, $artworkCache, $epg);
                        $batch = []; // Clear the batch
                        $batchIndex++;

                        // Small delay between batches
                        usleep(100000); // 100ms
                    }
                }
            }

            // Process remaining programs in the last batch
            if (! empty($batch)) {
                $this->processProgramBatchDirectly($batch, $batchIndex, $token, $chunkIndex, $scheduleChunk, $file, $programsWritten, $artworkCache, $epg);
            }
            Log::debug('Completed streaming direct program processing', [
                'chunk' => $chunkIndex,
                'total_batches' => $batchIndex + 1,
                'programs_written' => $programsWritten,
            ]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Process a batch of programs and immediately write matching schedule entries - no arrays
     */
    private function processProgramBatchDirectly(array $programBatch, int $batchIndex, string $token, int $chunkIndex, array $scheduleChunk, $file, int &$programsWritten, array $artworkCache = [], ?Epg $epg = null): void
    {
        if ($this->currentEpg?->hasValidSchedulesDirectToken()) {
            $token = $this->currentEpg->sd_token;
        }

        // Create a temporary file for the API response
        $tempResponseFile = tempnam(sys_get_temp_dir(), 'epg_programs_response_');
        try {
            Log::debug('Fetching program batch for direct processing', [
                'chunk' => $chunkIndex,
                'batch' => $batchIndex + 1,
                'batch_size' => count($programBatch),
            ]);

            // Fetch program artwork using the corrected metadata endpoint (if enabled)
            $programArtworkCache = [];
            if (self::$FETCH_PROGRAM_ARTWORK) {
                $programArtworkCache = $this->getProgramArtwork($token, $programBatch, $epg?->uuid);
                Log::debug("Fetched artwork for {$batchIndex} programs", ['artwork_count' => count($programArtworkCache)]);
            } else {
                Log::debug('Program artwork disabled for faster sync');
            }

            // Merge with existing artwork cache
            $fullArtworkCache = array_merge($artworkCache, ['programs' => $programArtworkCache]);

            $debugRetried = false;
            $tokenRetried = false;

            while (true) {
                $response = Http::withHeaders($this->buildHeaders($token))
                    ->connectTimeout(10)
                    ->timeout(300)
                    ->sink($tempResponseFile)
                    ->post(self::BASE_URL.'/'.self::API_VERSION.'/programs', $programBatch);
                $responseCode = $this->streamedProviderErrorCode($tempResponseFile);

                if ($responseCode === self::DEBUG_NOT_ENABLED_CODE && ! $debugRetried) {
                    $this->handleDebugNotEnabledError();
                    $debugRetried = true;

                    continue;
                }

                if ($responseCode === self::TOKEN_EXPIRED_CODE) {
                    if (! $tokenRetried && $replacementToken = $this->recoverCurrentEpgToken($token)) {
                        $token = $replacementToken;
                        $tokenRetried = true;

                        continue;
                    }

                    $this->invalidateCurrentEpgAuthentication($token);

                    throw new SchedulesDirectTokenExpiredException('Schedules Direct token remained expired after one recovery attempt.');
                }

                break;
            }

            if ($response->successful() && ($responseCode === null || $responseCode === 0)) {
                // Stream through the program response and match with schedules immediately
                $programs = Items::fromFile($tempResponseFile);
                foreach ($programs as $program) {
                    $programId = $program->programID ?? null;
                    if (! $programId) {
                        continue;
                    }

                    // Extract artwork URLs directly from program data if available
                    $programArtworkUrl = $this->extractArtworkFromProgram($program);
                    if ($programArtworkUrl) {
                        $fullArtworkCache['programs'][$programId] = $programArtworkUrl;
                    }

                    // Find matching schedule entries and write them immediately
                    foreach ($scheduleChunk as $schedule) {
                        $stationId = $schedule['stationID'] ?? null;
                        if (! $stationId) {
                            continue;
                        }

                        foreach ($schedule['programs'] ?? [] as $scheduleProgram) {
                            if ($scheduleProgram['programID'] === $programId) {
                                $this->writeProgramToXMLTV($file, $stationId, $scheduleProgram, $program, $fullArtworkCache);
                                $programsWritten++;
                            }
                        }
                    }
                }

                // Clear the JsonMachine iterator immediately
                unset($programs);
                Log::debug('Program batch processed directly', [
                    'chunk' => $chunkIndex,
                    'batch' => $batchIndex + 1,
                    'programs_in_batch' => count($programBatch),
                    'programs_written_in_batch' => $programsWritten,
                ]);
            } else {
                Log::error('Failed to fetch program batch', [
                    'chunk' => $chunkIndex,
                    'batch' => $batchIndex + 1,
                    'status' => $response->status(),
                    'code' => $responseCode,
                ]);
            }
        } catch (SchedulesDirectLoginCooldownException $exception) {
            throw $exception;
        } catch (SchedulesDirectTokenExpiredException $exception) {
            throw $exception;
        } catch (Exception $e) {
            Log::error('Error processing program batch directly', [
                'chunk' => $chunkIndex,
                'batch' => $batchIndex + 1,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Clean up temporary response file
            if (file_exists($tempResponseFile)) {
                unlink($tempResponseFile);
            }
        }
    }

    private function streamedProviderErrorCode(string $responseFile): ?int
    {
        if (! file_exists($responseFile)) {
            return null;
        }

        $handle = fopen($responseFile, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            $bodyPrefix = (string) fread($handle, self::STREAMED_ERROR_MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }

        $prefix = ltrim($bodyPrefix);

        if (! str_starts_with($prefix, '{')) {
            return null;
        }

        if (strlen($bodyPrefix) > self::STREAMED_ERROR_MAX_BYTES) {
            throw new Exception('Schedules Direct streamed error response was invalid.');
        }

        $errorData = json_decode($prefix, true);

        if (! is_array($errorData) || ! isset($errorData['code']) || ! is_numeric($errorData['code'])) {
            throw new Exception('Schedules Direct streamed error response was invalid.');
        }

        return (int) $errorData['code'];
    }

    /**
     * Write a single program to XMLTV file working directly with JsonMachine objects
     */
    private function writeProgramToXMLTV($file, string $stationId, array $scheduleProgram, $programData, array $artworkCache = []): void
    {
        // Handle schedule program data (always array)
        $airDateTime = $scheduleProgram['airDateTime'];
        $duration = $scheduleProgram['duration'];
        $isNew = $scheduleProgram['new'] ?? false;
        $start = Carbon::parse($airDateTime)->format('YmdHis O');
        $stop = Carbon::parse($airDateTime)->addSeconds($duration)->format('YmdHis O');

        // Start programme entry
        fwrite($file, "  <programme channel=\"{$stationId}\" start=\"{$start}\" stop=\"{$stop}\">\n");

        // Title - work directly with JsonMachine object
        if (! empty($programData->titles[0]->title120)) {
            $title = htmlspecialchars($programData->titles[0]->title120);
            fwrite($file, "    <title>{$title}</title>\n");
        }

        // Episode title
        if (! empty($programData->episodeTitle150)) {
            $subTitle = htmlspecialchars($programData->episodeTitle150);
            fwrite($file, "    <sub-title>{$subTitle}</sub-title>\n");
        }

        // Description
        if (! empty($programData->descriptions->description1000[0]->description)) {
            $desc = htmlspecialchars($programData->descriptions->description1000[0]->description);
            fwrite($file, "    <desc>{$desc}</desc>\n");
        }

        // Program artwork using proper XMLTV <image> tags
        $programId = $programData->programID ?? null;
        if ($programId && isset($artworkCache['programs'][$programId])) {
            $artworkList = $artworkCache['programs'][$programId];
            if (is_array($artworkList)) {
                // New format - multiple images with proper XMLTV attributes
                foreach ($artworkList as $artwork) {
                    $url = htmlspecialchars($artwork['url']);
                    $type = htmlspecialchars($artwork['type']);
                    $size = htmlspecialchars($artwork['size']);
                    $orient = htmlspecialchars($artwork['orient']);
                    $width = (int) ($artwork['width'] ?? 0);
                    $height = (int) ($artwork['height'] ?? 0);
                    fwrite($file, "    <icon src=\"{$url}\" type=\"{$type}\" width=\"{$width}\" height=\"{$height}\" orient=\"{$orient}\" size=\"{$size}\" />\n");
                }
            }
        }

        // Categories/Genres
        if (! empty($programData->genres)) {
            foreach ($programData->genres as $genre) {
                $genre = htmlspecialchars($genre);
                fwrite($file, "    <category>{$genre}</category>\n");
            }
        }

        // Episode numbering
        if (! empty($programData->metadata)) {
            foreach ($programData->metadata as $metadata) {
                if (isset($metadata->Gracenote->season) && isset($metadata->Gracenote->episode)) {
                    $season = max(0, $metadata->Gracenote->season - 1);
                    $episode = max(0, $metadata->Gracenote->episode - 1);
                    fwrite($file, "    <episode-num system=\"xmltv_ns\">{$season}.{$episode}.</episode-num>\n");
                    break;
                }
            }
        }

        // Content rating
        if (! empty($programData->contentRating)) {
            foreach ($programData->contentRating as $rating) {
                if ($rating->country === 'USA') {
                    $ratingSystem = htmlspecialchars($rating->body);
                    $ratingValue = htmlspecialchars($rating->code);
                    fwrite($file, "    <rating system=\"{$ratingSystem}\"><value>{$ratingValue}</value></rating>\n");
                    break;
                }
            }
        }

        // New flag
        if (! empty($isNew)) {
            fwrite($file, "    <new />\n");
        }

        // End programme entry
        fwrite($file, "  </programme>\n");
    }

    private function makeRequest(
        string $method,
        string $endpoint,
        array $data = [],
        ?string $token = null,
        bool $throwOnFailure = true,
        bool $quietLoginCooldown = false,
    ): Response {
        if ($token !== null && $this->currentEpg?->hasValidSchedulesDirectToken()) {
            $token = $this->currentEpg->sd_token;
        }

        $debugRetried = false;
        $tokenRetried = false;

        while (true) {
            $response = $this->sendRequestOnce($method, $endpoint, $data, $token);
            $body = $response->json();
            $body = is_array($body) ? $body : [];
            $responseCode = isset($body['code']) && is_numeric($body['code']) ? (int) $body['code'] : null;

            if ($responseCode === self::DEBUG_NOT_ENABLED_CODE && ! $debugRetried) {
                $this->handleDebugNotEnabledError();
                $debugRetried = true;

                continue;
            }

            if ($responseCode === self::TOKEN_EXPIRED_CODE) {
                if (! $tokenRetried && ($this->currentEpg || $this->rowlessCredentialSnapshot)) {
                    if ($replacementToken = $this->recoverCurrentEpgToken($token, $quietLoginCooldown)) {
                        $token = $replacementToken;
                        $tokenRetried = true;

                        continue;
                    }
                }

                $this->invalidateCurrentEpgAuthentication($token);

                throw new SchedulesDirectTokenExpiredException('Schedules Direct token remained expired after one recovery attempt.');
            }

            if ($response->failed() || ($responseCode !== null && $responseCode !== 0)) {
                $code = $responseCode ?? $response->status();

                Log::error('SchedulesDirect API error response', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'code' => $code,
                ]);

                if ($throwOnFailure) {
                    throw new Exception("Schedules Direct API request failed (code {$code}).", $code);
                }
            }

            return $response;
        }
    }

    private function invalidateCurrentEpgAuthentication(?string $rejectedToken = null): void
    {
        if (! $this->currentEpg?->hasSchedulesDirectCredentials()) {
            if ($this->rowlessCredentialSnapshot) {
                $credentialSnapshot = $this->rowlessCredentialSnapshot;
                $this->withAuthenticationLock(
                    $credentialSnapshot['provider_identifier'],
                    function (Closure $assertLockOwned) use ($credentialSnapshot, $rejectedToken): array {
                        $assertLockOwned();
                        $this->forgetRejectedAuthenticationHandoff($credentialSnapshot, $rejectedToken);

                        return [];
                    },
                );
            }

            return;
        }

        $this->withoutAuthenticationDatabaseDetails(function (): void {
            $credentialSnapshot = $this->credentialSnapshotFromEpg($this->currentEpg);
            $this->clearAuthenticationForCredentials($credentialSnapshot);
            $this->currentEpg->refresh();
        });
    }

    private function recoverCurrentEpgToken(?string $rejectedToken, bool $quietLoginCooldown = false): ?string
    {
        try {
            return $this->refreshCurrentEpgToken($rejectedToken, $quietLoginCooldown);
        } catch (SchedulesDirectLoginCooldownException $exception) {
            throw $exception;
        } catch (SchedulesDirectTokenExpiredException $exception) {
            throw $exception;
        } catch (Exception) {
            throw new SchedulesDirectTokenExpiredException('Schedules Direct token expired and could not be refreshed.');
        }
    }

    private function refreshCurrentEpgToken(?string $rejectedToken, bool $quietLoginCooldown = false): ?string
    {
        return $this->withoutAuthenticationDatabaseDetails(function () use ($rejectedToken, $quietLoginCooldown): ?string {
            if (! $this->currentEpg?->hasSchedulesDirectCredentials()) {
                if (! $this->rowlessCredentialSnapshot) {
                    return null;
                }

                $credentialSnapshot = $this->rowlessCredentialSnapshot;

                return $this->withAuthenticationLock(
                    $credentialSnapshot['provider_identifier'],
                    function (Closure $assertLockOwned) use ($credentialSnapshot, $rejectedToken, $quietLoginCooldown): array {
                        $assertLockOwned();

                        if ($reusableAuthentication = $this->findReusableAuthentication($credentialSnapshot)) {
                            $authentication = $reusableAuthentication['authentication'];

                            if (! hash_equals($authentication['token'], $rejectedToken ?? '')) {
                                return $authentication;
                            }
                        }

                        $this->forgetRejectedAuthenticationHandoff($credentialSnapshot, $rejectedToken);

                        if ($activeCooldown = $this->findActiveCooldown($credentialSnapshot, $quietLoginCooldown)) {
                            throw $this->activeLoginCooldownException($credentialSnapshot, $activeCooldown, null, $quietLoginCooldown);
                        }

                        $authentication = $this->requestAuthentication(
                            $credentialSnapshot,
                            assertLockOwned: $assertLockOwned,
                            quietLoginCooldown: $quietLoginCooldown,
                        );
                        $assertLockOwned();
                        $this->persistAuthentication($credentialSnapshot, $authentication, storeHandoffWhenRowless: true);

                        return $authentication;
                    },
                )['token'];
            }

            $epg = $this->currentEpg;

            if ($quietLoginCooldown && $retryAt = $epg->activeSchedulesDirectLoginCooldownUntil()) {
                throw new SchedulesDirectLoginCooldownException($retryAt);
            }

            $authentication = $this->withFreshEpgCredentialLock($epg, function (array $credentialSnapshot, Closure $assertLockOwned) use ($epg, $rejectedToken, $quietLoginCooldown): array {
                if ($quietLoginCooldown && $activeCooldown = $this->findActiveCooldown($credentialSnapshot, quiet: true)) {
                    throw new SchedulesDirectLoginCooldownException($activeCooldown['cooldown_until']);
                }

                $this->claimCredentialRows($credentialSnapshot);

                if ($retryWith = $this->refreshedCredentialSnapshotIfChanged($epg, $credentialSnapshot)) {
                    return ['retry_with' => $retryWith];
                }

                $validAuthentication = $this->findValidAuthenticationForEpg($epg, $credentialSnapshot);

                if ($validAuthentication && ! hash_equals($validAuthentication['token'], $rejectedToken ?? '')) {
                    $assertLockOwned();

                    return $validAuthentication;
                }

                $assertLockOwned();
                $this->clearAuthenticationForCredentials($credentialSnapshot);
                $epg->refresh();

                if ($activeCooldown = $this->findActiveCooldown($credentialSnapshot, $quietLoginCooldown)) {
                    throw $this->activeLoginCooldownException($credentialSnapshot, $activeCooldown, $epg, $quietLoginCooldown);
                }

                if ($retryWith = $this->refreshedCredentialSnapshotIfChanged($epg, $credentialSnapshot)) {
                    return ['retry_with' => $retryWith];
                }

                $authentication = $this->requestAuthentication($credentialSnapshot, $epg, $assertLockOwned, $quietLoginCooldown);

                if ($retryWith = $this->refreshedCredentialSnapshotIfChanged($epg, $credentialSnapshot)) {
                    return ['retry_with' => $retryWith];
                }

                $assertLockOwned();
                if ($this->persistAuthentication($credentialSnapshot, $authentication) === 0) {
                    $epg->refresh();

                    return ['retry_with' => $this->credentialSnapshotFromEpg($epg)];
                }

                return $authentication;
            });
            $epg->refresh();

            return $authentication['token'];
        });
    }

    private function clearAuthenticationForCredentials(array $credentialSnapshot): void
    {
        Cache::forget($this->authenticationHandoffKey($credentialSnapshot));

        Epg::query()
            ->where('user_id', $credentialSnapshot['owner_id'])
            ->where('sd_account_identifier', $credentialSnapshot['identifier'])
            ->update([
                'sd_token' => null,
                'sd_token_expires_at' => null,
            ]);
    }

    private function forgetRejectedAuthenticationHandoff(array $credentialSnapshot, ?string $rejectedToken): void
    {
        $key = $this->authenticationHandoffKey($credentialSnapshot);
        $authentication = Cache::get($key);

        if (is_string($authentication['token'] ?? null)
            && is_string($rejectedToken)
            && hash_equals($authentication['token'], $rejectedToken)
        ) {
            Cache::forget($key);
        }
    }

    /**
     * Send one authenticated request without provider-code retries.
     */
    private function sendRequestOnce(string $method, string $endpoint, array $data = [], ?string $token = null): Response
    {
        $headers = $this->buildHeaders($token);
        $url = self::BASE_URL.'/'.self::API_VERSION.$endpoint;

        // Configure timeout based on endpoint and data size
        $timeout = self::DEFAULT_TIMEOUT;
        if (str_contains($endpoint, '/schedules')) {
            $timeout = self::SCHEDULES_TIMEOUT;
        } elseif (str_contains($endpoint, '/programs')) {
            $dataSize = is_array($data) ? count($data) : 0;
            // Scale timeout based on data size for program requests
            if ($dataSize > 1000) {
                $timeout = 300; // 5 minutes for very large batches
            } elseif ($dataSize > 500) {
                $timeout = 180; // 3 minutes for large batches
            } else {
                $timeout = 90; // 1.5 minutes for medium batches
            }
        } elseif (str_contains($endpoint, '/schedules/md5')) {
            $timeout = 45; // Hash requests are faster but still need time
        }

        $request = Http::withHeaders($headers)
            ->connectTimeout(10)
            ->timeout($timeout)
            ->retry(
                2,
                1000,
                fn (Exception $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            )
            ->withOptions([
                'verify' => true,
                'stream' => false, // Disable streaming to prevent memory issues
                'max_redirects' => 3,
                'allow_redirects' => ['strict' => true],
            ]);

        Log::debug('Making SchedulesDirect API request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'timeout' => $timeout,
            'data_size' => is_array($data) ? count($data) : 0,
            'has_token' => ! empty($token),
        ]);
        try {
            $startTime = microtime(true);
            if ($method === 'GET' && ! empty($data)) {
                $url .= '?'.http_build_query($data);
                $response = $request->get($url);
            } elseif ($method === 'POST') {
                $response = $request->post($url, $data);
            } elseif ($method === 'PUT') {
                $response = $request->put($url, $data);
            } else {
                $response = $request->send($method, $url, ['json' => $data]);
            }
            $duration = round(microtime(true) - $startTime, 2);
            Log::debug('SchedulesDirect API request completed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'duration_seconds' => $duration,
                'status_code' => $response->status(),
                'response_size' => strlen($response->body()),
            ]);
        } catch (Exception $exception) {
            Log::error('SchedulesDirect API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'timeout' => $timeout,
                'error_class' => $exception::class,
            ]);

            throw new Exception('Schedules Direct API request failed.');
        }

        return $response;
    }
}
