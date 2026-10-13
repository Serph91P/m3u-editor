<?php

namespace App\Services;

use App\Models\EmbyLibraryMapping;
use App\Models\User;

class ManagedCacheControlService
{
    public function resolveActiveMapping(
        User $user,
        int $integrationId,
        string $mappingUuid,
        string $revision,
    ): ?EmbyLibraryMapping {
        return EmbyLibraryMapping::query()
            ->whereBelongsTo($user)
            ->where('media_server_integration_id', $integrationId)
            ->where('uuid', $mappingUuid)
            ->where('enabled', true)
            ->where('is_managed', true)
            ->where('last_applied_revision', $revision)
            ->whereHas('integration', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('type', 'emby')
                ->where('enabled', true))
            ->first();
    }
}
