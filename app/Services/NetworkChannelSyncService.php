<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Group;
use App\Models\Network;
use App\Models\Playlist;

/**
 * Projects Networks into real Channel + Group rows on their network playlist,
 * so merged playlists, M3U/Xtream output and channel counts see network
 * content without special-casing (issue #1303).
 */
class NetworkChannelSyncService
{
    /**
     * Network attributes the projected channel is built from. Saves that touch
     * none of these (e.g. broadcast state) skip the projection entirely.
     */
    public const PROJECTED_ATTRIBUTES = [
        'name',
        'logo',
        'channel_number',
        'enabled',
        'group_name',
        'network_playlist_id',
    ];

    /**
     * Project every network that outputs to a network playlist, and drop
     * projections for networks that no longer output to it.
     */
    public function syncNetworksToPlaylist(Playlist $playlist): int
    {
        if (! $playlist->is_network_playlist) {
            return 0;
        }

        $networkIds = [];
        foreach ($playlist->networks()->cursor() as $network) {
            $this->upsertChannel($playlist, $network);
            $networkIds[] = $network->id;
        }

        Channel::where('playlist_id', $playlist->id)
            ->whereNotNull('network_id')
            ->whereNotIn('network_id', $networkIds)
            ->delete();

        $this->pruneEmptyGroups([$playlist->id]);

        return count($networkIds);
    }

    /**
     * Project a single network: drop its rows from playlists it no longer
     * outputs to, upsert its channel on the one it does, and prune groups the
     * change left empty.
     */
    public function syncNetwork(Network $network): void
    {
        $playlistId = $network->network_playlist_id;
        $affectedPlaylistIds = $this->removeChannels($network, except: $playlistId);

        $playlist = $playlistId ? Playlist::find($playlistId) : null;
        if ($playlist) {
            $this->upsertChannel($playlist, $network);
            $affectedPlaylistIds[] = $playlist->id;
        }

        $this->pruneEmptyGroups($affectedPlaylistIds);
    }

    /**
     * Remove a network's projection everywhere, pruning groups left empty.
     */
    public function removeNetwork(Network $network): void
    {
        $this->pruneEmptyGroups($this->removeChannels($network));
    }

    /**
     * Upsert the channel row for a network on its playlist.
     */
    public function upsertChannel(Playlist $playlist, Network $network): Channel
    {
        $groupName = $network->effective_group_name;
        $group = Group::firstOrCreate(
            ['playlist_id' => $playlist->id, 'name' => $groupName],
            ['user_id' => $playlist->user_id, 'name_internal' => $groupName, 'enabled' => true],
        );

        return Channel::updateOrCreate(
            ['playlist_id' => $playlist->id, 'network_id' => $network->id],
            [
                'user_id' => $playlist->user_id,
                'name' => $network->name,
                'title' => $network->name,
                // Fixed HLS endpoint on the configured base URL: unlike the
                // stream_url accessor it doesn't depend on broadcast state or the
                // host of whichever request saved the network, and the endpoint
                // starts on-demand broadcasts itself.
                'url' => PlaylistService::getBaseUrl(route('network.hls.playlist', ['network' => $network->uuid], absolute: false)),
                'logo' => $network->logo,
                'group' => $group->name,
                'group_id' => $group->id,
                'group_internal' => $group->name,
                'channel' => $network->channel_number,
                'enabled' => $network->enabled,
                'is_vod' => false,
                'stream_id' => 'network-'.$network->id,
                'source_id' => 'network-'.$network->id,
            ],
        );
    }

    /**
     * Delete a network's channels (optionally keeping the ones on one
     * playlist) and return the playlists they were removed from.
     *
     * @return list<int>
     */
    protected function removeChannels(Network $network, ?int $except = null): array
    {
        $query = Channel::where('network_id', $network->id)
            ->when($except !== null, fn ($query) => $query->where('playlist_id', '!=', $except));

        $playlistIds = (clone $query)->distinct()->pluck('playlist_id')->all();
        if ($playlistIds !== []) {
            $query->delete();
        }

        return $playlistIds;
    }

    /**
     * Delete projected groups that no longer hold any channels. Network
     * playlists only contain projected groups, so anything empty and
     * non-custom there is a leftover from a renamed, moved or removed network.
     * They carry no user data, so they're removed outright rather than
     * soft-deleted (which would pile up a trashed row per rename).
     *
     * @param  list<int>  $playlistIds
     */
    protected function pruneEmptyGroups(array $playlistIds): void
    {
        if ($playlistIds === []) {
            return;
        }

        Group::whereIn('playlist_id', Playlist::whereIn('id', array_unique($playlistIds))
            ->where('is_network_playlist', true)
            ->select('id'))
            ->where('custom', false)
            ->whereDoesntHave('channels')
            ->forceDelete();
    }
}
