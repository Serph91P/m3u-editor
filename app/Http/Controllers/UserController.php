<?php

namespace App\Http\Controllers;

use App\Services\M3uProxyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Get your Playlists.
     *
     * Returns an array of your Playlists, Custom Playlists, Merged Playlists, and Playlist
     * Aliases with detailed information including channel counts and proxy settings. This is
     * useful for calling the Playlist/Custom Playlist/Merged Playlist/Playlist Alias endpoints
     * as a UUID is required. Use `type` (`playlist`, `custom_playlist`, `merged_playlist`, or
     * `playlist_alias`) to tell them apart. `last_sync`/`status`/`source_type` are only
     * meaningful for standard playlists and are always `null` on the other types, since they
     * don't sync from a source of their own.
     *
     * @return []|\Illuminate\Http\Response
     *
     * @response 200 [
     *   {
     *     "name": "My Provider",
     *     "uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100",
     *     "type": "playlist",
     *     "total_channels": 500,
     *     "enabled_channels": 450,
     *     "live_channels": 400,
     *     "vod_channels": 100,
     *     "groups_count": 25,
     *     "proxy_enabled": true,
     *     "active_streams": 3,
     *     "last_sync": "2026-01-14T10:00:00+00:00",
     *     "status": "Active",
     *     "source_type": "m3u"
     *   },
     *   {
     *     "name": "Family Lineup",
     *     "uuid": "1eff7923-cbd1-4868-9fed-2e3748ac1101",
     *     "type": "custom_playlist",
     *     "total_channels": 40,
     *     "enabled_channels": 40,
     *     "live_channels": 40,
     *     "vod_channels": 0,
     *     "groups_count": 3,
     *     "proxy_enabled": false,
     *     "active_streams": 0,
     *     "last_sync": null,
     *     "status": null,
     *     "source_type": null
     *   },
     *   {
     *     "name": "All Providers Merged",
     *     "uuid": "2eff7923-cbd1-4868-9fed-2e3748ac1102",
     *     "type": "merged_playlist",
     *     "total_channels": 900,
     *     "enabled_channels": 850,
     *     "live_channels": 700,
     *     "vod_channels": 200,
     *     "groups_count": 40,
     *     "proxy_enabled": false,
     *     "active_streams": 0,
     *     "last_sync": null,
     *     "status": null,
     *     "source_type": null
     *   },
     *   {
     *     "name": "Reseller Alias",
     *     "uuid": "3eff7923-cbd1-4868-9fed-2e3748ac1103",
     *     "type": "playlist_alias",
     *     "total_channels": 500,
     *     "enabled_channels": 450,
     *     "live_channels": 400,
     *     "vod_channels": 100,
     *     "groups_count": 25,
     *     "proxy_enabled": true,
     *     "active_streams": 1,
     *     "last_sync": null,
     *     "status": null,
     *     "source_type": null
     *   }
     * ]
     */
    public function playlists(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
        }

        $playlists = $user->playlists()
            ->withCount($this->channelCountScopes('groups'))
            ->get()
            ->map(fn ($playlist) => $this->mapPlaylistForApi($playlist, 'playlist', withSyncStatus: true));

        $customPlaylists = $user->customPlaylists()
            ->withCount($this->channelCountScopes('groupTags as groups_count'))
            ->get()
            ->map(fn ($playlist) => $this->mapPlaylistForApi($playlist, 'custom_playlist'));

        $mergedPlaylists = $user->mergedPlaylists()
            ->withCount($this->channelCountScopes('groups'))
            ->get()
            ->map(fn ($playlist) => $this->mapPlaylistForApi($playlist, 'merged_playlist'));

        // Playlist Aliases resolve their channels/groups relations dynamically based on which
        // source playlist type they point to (Playlist, CustomPlaylist, or MergedPlaylist), so
        // withCount() can't build a single subquery for the whole collection here the way it
        // can for the other playlist types above; each alias's counts are queried individually.
        $playlistAliases = $user->playlistAliases()
            ->get()
            ->map(fn ($alias) => [
                'name' => $alias->name,
                'uuid' => $alias->uuid,
                'type' => 'playlist_alias',
                'total_channels' => $alias->channels()->count(),
                'enabled_channels' => $alias->enabled_channels()->count(),
                'live_channels' => $alias->channels()->where('channels.is_vod', false)->count(),
                'vod_channels' => $alias->channels()->where('channels.is_vod', true)->count(),
                'groups_count' => $alias->groups()->count(),
                'proxy_enabled' => (bool) $alias->enable_proxy,
                'active_streams' => $this->activeStreamsCount($alias),
                'last_sync' => null,
                'status' => null,
                'source_type' => null,
            ]);

        return $playlists->concat($customPlaylists)
            ->concat($mergedPlaylists)
            ->concat($playlistAliases)
            ->values()
            ->toArray();
    }

    /**
     * Build the withCount() relation scopes shared by Playlists, Custom Playlists, and Merged
     * Playlists. Only the groups relation/alias differs between them.
     *
     * @return array<int|string, string|\Closure>
     */
    private function channelCountScopes(string $groupsScope): array
    {
        return [
            'channels',
            'channels as enabled_channels_count' => function ($query) {
                $query->where('enabled', true);
            },
            'channels as live_channels_count' => function ($query) {
                $query->where('is_vod', false);
            },
            'channels as vod_channels_count' => function ($query) {
                $query->where('is_vod', true);
            },
            $groupsScope,
        ];
    }

    /**
     * Map a Playlist, Custom Playlist, or Merged Playlist model (already annotated with the
     * counts from channelCountScopes()) into the shared playlists() API response shape.
     *
     * @return array<string, mixed>
     */
    private function mapPlaylistForApi(Model $playlist, string $type, bool $withSyncStatus = false): array
    {
        return [
            'name' => $playlist->name,
            'uuid' => $playlist->uuid,
            'type' => $type,
            'total_channels' => $playlist->channels_count,
            'enabled_channels' => $playlist->enabled_channels_count,
            'live_channels' => $playlist->live_channels_count,
            'vod_channels' => $playlist->vod_channels_count,
            'groups_count' => $playlist->groups_count,
            'proxy_enabled' => (bool) $playlist->enable_proxy,
            'active_streams' => $this->activeStreamsCount($playlist),
            'last_sync' => $withSyncStatus ? $playlist->synced?->toIso8601String() : null,
            'status' => $withSyncStatus ? ($playlist->status?->value ?? 'Unknown') : null,
            'source_type' => $withSyncStatus ? ($playlist->source_type?->value ?? 'unknown') : null,
        ];
    }

    /**
     * Get the cached active streams count for a playlist-like model, or 0 if proxying is off.
     */
    private function activeStreamsCount(Model $playlist): int
    {
        return $playlist->enable_proxy
            ? M3uProxyService::getCachedPlaylistActiveStreamsCount($playlist, 5)
            : 0;
    }

    /**
     * Get your EPGs.
     *
     * Returns an array of your EPGs with detailed information including channel counts
     * and sync status. This is useful for calling the EPG endpoints as a UUID is required.
     *
     * @return []|\Illuminate\Http\Response
     *
     * @response 200 [
     *   {
     *     "name": "My EPG Guide",
     *     "uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100",
     *     "channel_count": 200,
     *     "last_sync": "2026-01-14T08:00:00+00:00",
     *     "status": "Active",
     *     "source_type": "xmltv",
     *     "is_processing": false
     *   }
     * ]
     */
    public function epgs(Request $request)
    {
        $user = $request->user();
        if ($user) {
            return $user->epgs()
                ->withCount('channels')
                ->get()
                ->map(function ($epg) {
                    return [
                        'name' => $epg->name,
                        'uuid' => $epg->uuid,
                        'channel_count' => $epg->channels_count,
                        'last_sync' => $epg->synced?->toIso8601String(),
                        'status' => $epg->status?->value ?? 'Unknown',
                        'source_type' => $epg->source_type?->value ?? 'xmltv',
                        'is_processing' => (bool) $epg->processing,
                    ];
                })->toArray();
        }

        return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
    }
}
