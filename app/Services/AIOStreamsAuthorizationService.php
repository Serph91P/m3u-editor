<?php

namespace App\Services;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;

/**
 * Shared AIOStreams authorization logic, used by both the Xtream API's
 * feature-advertisement response and the direct AIOStreams proxy routes so
 * the two can never drift out of sync (see #1384).
 */
class AIOStreamsAuthorizationService
{
    /**
     * Unwrap a PlaylistAlias to its effective playlist; otherwise return the
     * playlist as-is if it's one of the three playlist types that carry
     * their own AIOStreams integration assignment.
     */
    public function resolveEffectivePlaylist($playlist): Playlist|CustomPlaylist|MergedPlaylist|null
    {
        $effective = $playlist instanceof PlaylistAlias
            ? $playlist->getEffectivePlaylist()
            : $playlist;

        return $effective instanceof Playlist || $effective instanceof CustomPlaylist || $effective instanceof MergedPlaylist
            ? $effective
            : null;
    }

    /**
     * Whether the given credentials may use AIOStreams at all (i.e. an
     * enabled integration is assigned to the effective playlist and, for
     * PlaylistAuth credentials, the individual auth has it enabled).
     */
    public function isEnabled($playlist, string $authMethod, ?PlaylistAuth $playlistAuth): bool
    {
        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);

        if (! $effectivePlaylist) {
            return false;
        }

        $hasEnabledAiostreams = $effectivePlaylist->aiostreams_integration_id !== null
            && optional($effectivePlaylist->aiostreamsIntegration)->enabled;

        if (! $hasEnabledAiostreams) {
            return false;
        }

        if ($authMethod !== 'playlist_auth') {
            return true;
        }

        return (bool) $playlistAuth?->aiostreams_enabled;
    }

    /**
     * Whether the given credentials are authorized to reach the specific
     * AIOStreams integration ID (i.e. it's the one assigned to their
     * effective playlist, not just any enabled integration owned by the
     * same user).
     */
    public function isAuthorizedForIntegration($playlist, string $authMethod, ?PlaylistAuth $playlistAuth, int $integrationId): bool
    {
        if (! $this->isEnabled($playlist, $authMethod, $playlistAuth)) {
            return false;
        }

        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);

        return $effectivePlaylist?->aiostreams_integration_id === $integrationId;
    }
}
