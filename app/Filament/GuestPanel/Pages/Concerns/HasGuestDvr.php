<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

use App\Facades\PlaylistFacade;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Services\DvrCapabilityGate;
use Illuminate\Database\Eloquent\Builder;

trait HasGuestDvr
{
    use HasPlaylist;

    /**
     * Resolve the PlaylistAuth for the current guest session.
     */
    public static function getCurrentPlaylistAuth(): ?PlaylistAuth
    {
        $credentials = static::getCurrentAuth();
        if (! $credentials) {
            return null;
        }

        return PlaylistAuth::where('username', $credentials['username'])
            ->where('password', $credentials['password'])
            ->where('enabled', true)
            ->first();
    }

    /**
     * Whether the current guest-panel session belongs to the playlist owner,
     * authenticated with their m3u-editor username and the playlist UUID as
     * the password (PlaylistService::authenticate()'s "owner_auth" fallback),
     * rather than a PlaylistAuth record. Owners have no PlaylistAuth of their
     * own, so this is the only way to recognize them in the guest panel.
     */
    protected static function isOwnerAuth(): bool
    {
        if (static::getCurrentPlaylistAuth() !== null) {
            return false;
        }

        $credentials = static::getCurrentAuth();
        if (! $credentials) {
            return false;
        }

        $result = PlaylistFacade::authenticate($credentials['username'], $credentials['password']);

        return is_array($result) && ($result[1] ?? null) === 'owner_auth';
    }

    /**
     * Whether the current session owns a record carrying the given
     * playlist_auth_id.
     *
     * Guests own only the records they created themselves. The playlist owner
     * has no PlaylistAuth record, so the records they own are the ones with a
     * null playlist_auth_id.
     */
    protected static function sessionOwnsRecord(?PlaylistAuth $auth, int|string|null $playlistAuthId): bool
    {
        if ($auth !== null) {
            return $playlistAuthId === $auth->id;
        }

        return $playlistAuthId === null && static::isOwnerAuth();
    }

    /**
     * Restrict a query to the records owned by the current session.
     *
     * A null PlaylistAuth is only safe to treat as "the playlist owner" when
     * isOwnerAuth() confirms it — otherwise (a guest session that failed to
     * resolve) where('playlist_auth_id', null) becomes whereNull() and leaks
     * the owner's records to that guest.
     */
    protected static function restrictToSessionOwner(Builder $query): Builder
    {
        $auth = static::getCurrentPlaylistAuth();

        if (! $auth && ! static::isOwnerAuth()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('playlist_auth_id', $auth?->id);
    }

    /**
     * Resolve the DvrSetting for the current guest's assigned playlist
     * (Playlist, CustomPlaylist, MergedPlaylist, or an alias of one of those).
     */
    public static function getDvrSetting(): ?DvrSetting
    {
        $uuid = static::getCurrentUuid();
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);

        if ($playlist instanceof Playlist || $playlist instanceof CustomPlaylist || $playlist instanceof MergedPlaylist) {
            return $playlist->dvrSetting;
        }

        if ($playlist instanceof PlaylistAlias) {
            return $playlist->getEffectivePlaylist()?->dvrSetting;
        }

        return null;
    }

    /**
     * Whether the current session is permitted to use DVR features.
     *
     * Guests (PlaylistAuth) are gated by their own dvr_enabled flag. The
     * playlist owner has no PlaylistAuth record, so their access is gated by
     * the playlist-level DvrSetting::$enabled flag instead.
     */
    protected static function guestCanAccessDvr(): bool
    {
        $auth = static::getCurrentPlaylistAuth();

        if (! DvrCapabilityGate::granted(static::getDvrSetting(), $auth, $auth !== null)) {
            return false;
        }

        return $auth !== null || static::isOwnerAuth();
    }
}
