<?php

namespace App\Services;

use App\Models\DvrSetting;
use App\Models\PlaylistAuth;

/**
 * Single source of truth for whether DVR is currently usable, shared by every
 * surface that advertises or enforces DVR access: the Xtream player_api feature
 * advertisement and direct action dispatch, the guest panel, and direct
 * recording playback/download. All of them must agree on the same result so a
 * capability that isn't advertised can never be reached through a direct
 * action instead.
 */
class DvrCapabilityGate
{
    /**
     * @param  DvrSetting|null  $dvrSetting  The effective playlist's DVR setting, if any.
     * @param  PlaylistAuth|null  $playlistAuth  The authenticated guest credential, if the caller is a guest.
     * @param  bool  $isGuestCredential  Whether the current session authenticated as a guest (PlaylistAuth) rather than the playlist owner.
     */
    public static function granted(?DvrSetting $dvrSetting, ?PlaylistAuth $playlistAuth, bool $isGuestCredential): bool
    {
        if (! (config('dvr.dvr_enabled', true) && config('proxy.proxy_integration_enabled', true))) {
            return false;
        }

        if (! $dvrSetting?->enabled) {
            return false;
        }

        if (! $isGuestCredential) {
            return true;
        }

        return (bool) $playlistAuth?->dvr_enabled;
    }
}
