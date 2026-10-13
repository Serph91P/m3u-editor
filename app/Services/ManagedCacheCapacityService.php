<?php

namespace App\Services;

use App\Enums\CacheDispatchResult;

/**
 * Decides whether a managed-publishing prefetch may consume a provider slot.
 *
 * Callers must pass a current, authoritative provider-wide usage snapshot;
 * missing or unbounded capacity deliberately remains deferred rather than
 * treating any playlist-local count as safe external capacity.
 */
class ManagedCacheCapacityService
{
    public function availability(?int $limit, ?int $live, ?int $background): CacheDispatchResult
    {
        if ($limit === null || $limit < 1 || $live === null || $background === null) {
            return CacheDispatchResult::Deferred;
        }

        if ($background > 0 || $live >= $limit - 1) {
            return CacheDispatchResult::Deferred;
        }

        return CacheDispatchResult::Queued;
    }
}
