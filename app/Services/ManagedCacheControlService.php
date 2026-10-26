<?php

namespace App\Services;

use App\Models\CachedContentFile;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManagedCacheControlService
{
    /**
     * Persist additive consumer metadata for an already selected cache file.
     * This deliberately does not alter the file ownership: a shared/manual
     * cache may satisfy a request but is never adopted by this feature.
     */
    public function recordInterest(
        CachedContentFile $file,
        MediaServerIntegration $integration,
        string $mappingUuid,
        string $canonicalId,
        string $variantKey,
        string $consumerId,
    ): ManagedCacheInterest {
        return app(CachedContentActivityService::class)->withFileActivityLock($file, function () use ($file, $integration, $mappingUuid, $canonicalId, $variantKey, $consumerId): ManagedCacheInterest {
            return DB::transaction(function () use ($file, $integration, $mappingUuid, $canonicalId, $variantKey, $consumerId): ManagedCacheInterest {
                CachedContentFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();

                return ManagedCacheInterest::firstOrCreate([
                    'cached_content_file_id' => $file->id,
                    'integration_id' => $integration->id,
                    'mapping_uuid' => $mappingUuid,
                    'canonical_id' => $canonicalId,
                    'variant_key' => $variantKey,
                    'consumer_id' => $consumerId,
                ]);
            });
        });
    }

    /**
     * Record a watched/undo decision for one consumer interest. Only a
     * complete cohort with watched=true makes the interest releasable;
     * watched=false cancels any pending release.
     */
    public function releaseWatched(
        User $user,
        MediaServerIntegration $integration,
        string $mappingUuid,
        string $canonicalId,
        string $variantKey,
        string $consumerId,
        bool $watched,
        int $deleteAfterHours,
        bool $cohortComplete,
    ): void {
        $interest = ManagedCacheInterest::query()
            ->where('integration_id', $integration->id)
            ->whereHas('integration', fn ($query) => $query->where('user_id', $user->id))
            ->where('mapping_uuid', $mappingUuid)
            ->where('canonical_id', $canonicalId)
            ->where('variant_key', $variantKey)
            ->where('consumer_id', $consumerId)
            ->first();

        if ($interest === null) {
            return;
        }

        $file = $interest->cachedContentFile;
        if ($file === null) {
            return;
        }

        app(CachedContentActivityService::class)->withFileActivityLock($file, function () use ($interest, $watched, $deleteAfterHours, $cohortComplete): void {
            $interest = $interest->fresh();
            if ($interest === null) {
                return;
            }

            if (! $watched || ! $cohortComplete) {
                $interest->update([
                    'watched_at' => null,
                    'release_after' => null,
                ]);

                return;
            }

            $earliest = now()->copy()->addMinutes(15);
            $releaseAfter = now()->copy()->addHours(max(0, min(168, $deleteAfterHours)));

            $interest->update([
                'watched_at' => now(),
                'release_after' => $releaseAfter->greaterThan($earliest) ? $releaseAfter : $earliest,
            ]);
        });
    }
}
