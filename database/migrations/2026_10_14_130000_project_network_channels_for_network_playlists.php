<?php

use App\Models\Group;
use App\Models\Playlist;
use App\Services\NetworkChannelSyncService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfill: project existing network playlists' networks into real
     * Channel + Group rows (issue #1303). Row inserts only (no DDL), so the
     * hot channels table is never locked.
     */
    public function up(): void
    {
        Playlist::where('is_network_playlist', true)
            ->chunkById(100, function ($playlists): void {
                $service = app(NetworkChannelSyncService::class);

                foreach ($playlists as $playlist) {
                    $service->syncNetworksToPlaylist($playlist);
                }
            });
    }

    public function down(): void
    {
        Playlist::where('is_network_playlist', true)
            ->chunkById(100, function ($playlists): void {
                foreach ($playlists as $playlist) {
                    $playlist->channels()->whereNotNull('network_id')->delete();
                    Group::where('playlist_id', $playlist->id)->where('custom', false)->whereDoesntHave('channels')->forceDelete();
                }
            });
    }
};
