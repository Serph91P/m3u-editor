<?php

namespace App\Console\Commands;

use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Channel;
use App\Models\Episode;
use Illuminate\Console\Command;

/**
 * Low-frequency sweep for AIOStreams-backed content that has never
 * successfully resolved a stream yet — either because it was added before
 * its air date ('scheduled', now due) or because its initial resolution
 * attempts all came up empty ('failed').
 *
 * Deliberately does NOT touch anything with aio_resolution_status='resolved'.
 * Some debrid backends (confirmed: TorBox) ban accounts for repeatedly
 * re-checking a link that's already active — periodically "refreshing"
 * working candidates is exactly that pattern, so this command only ever
 * attempts a *first* real resolution for entries that don't have one yet.
 * Ordinary link rot on already-resolved content is handled separately, by
 * M3uProxyService::resolveFailoverUrl()'s exhaustion hook at actual
 * playback-failure time.
 */
class ResolvePendingAioStreamsCandidates extends Command
{
    protected $signature = 'app:resolve-pending-aiostreams-candidates {--limit=20}';

    protected $description = 'Resolve a small capped batch of AIOStreams-backed VOD/episode entries that have never resolved a stream (due air date or previously-empty results)';

    public function handle(): void
    {
        $limit = (int) $this->option('limit');

        $channels = Channel::query()
            ->where('is_custom', true)
            ->whereNotNull('aio_integration_id')
            ->where('aio_resolution_status', 'failed')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($channels as $channel) {
            ResolveAioStreamsChannel::dispatch($channel->id);
        }

        $remaining = max(0, $limit - $channels->count());

        $episodes = Episode::query()
            ->where('is_custom', true)
            ->whereNotNull('aio_item_id')
            ->where(function ($query) {
                $query->where('aio_resolution_status', 'failed')
                    ->orWhere(function ($query) {
                        $query->where('aio_resolution_status', 'scheduled')
                            ->where('aio_air_date', '<=', now());
                    });
            })
            ->orderBy('aio_air_date')
            ->limit($remaining)
            ->get();

        foreach ($episodes as $episode) {
            ResolveAioStreamsEpisode::dispatch($episode->id);
        }

        $this->info("Resolving {$channels->count()} channels and {$episodes->count()} episodes.");
    }
}
