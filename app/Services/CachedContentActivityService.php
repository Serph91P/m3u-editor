<?php

namespace App\Services;

use App\Models\CachedContentActivityLease;
use App\Models\MediaServerIntegration;
use App\Models\User;

class CachedContentActivityService
{
    private const int PLAYBACK_TTL_SECONDS = 120;

    /** @param array{mapping_uuid: string, revision: string, canonical_id: string, variant_key: string} $target */
    public function acquirePlayback(
        User $user,
        MediaServerIntegration $integration,
        string $leaseId,
        int $sequence,
        array $target,
    ): CachedContentActivityLease {
        $lease = CachedContentActivityLease::firstOrNew([
            'integration_id' => $integration->id,
            'kind' => 'playback',
            'lease_id' => $leaseId,
        ]);

        if ($lease->exists && $sequence <= $lease->sequence) {
            return $lease;
        }

        $now = now();
        $lease->fill([
            'user_id' => $user->id,
            'mapping_uuid' => $target['mapping_uuid'],
            'revision' => $target['revision'],
            'canonical_id' => $target['canonical_id'],
            'variant_key' => $target['variant_key'],
            'sequence' => $sequence,
            'expires_at' => $now->copy()->addSeconds(self::PLAYBACK_TTL_SECONDS),
            'last_activity_at' => $now,
        ]);
        $lease->save();

        return $lease;
    }

    public function stopPlayback(MediaServerIntegration $integration, string $leaseId, int $sequence): void
    {
        CachedContentActivityLease::query()
            ->where('integration_id', $integration->id)
            ->where('kind', 'playback')
            ->where('lease_id', $leaseId)
            ->where('sequence', '<', $sequence)
            ->update([
                'sequence' => $sequence,
                'expires_at' => now(),
                'last_activity_at' => now(),
            ]);
    }

    /** @param array{mapping_uuid: string, revision: string, canonical_id: string, variant_key: string} $target */
    public function hasActivePlayback(array $target): bool
    {
        return CachedContentActivityLease::query()
            ->where('kind', 'playback')
            ->where('mapping_uuid', $target['mapping_uuid'])
            ->where('revision', $target['revision'])
            ->where('canonical_id', $target['canonical_id'])
            ->where('variant_key', $target['variant_key'])
            ->where('expires_at', '>', now())
            ->exists();
    }
}
