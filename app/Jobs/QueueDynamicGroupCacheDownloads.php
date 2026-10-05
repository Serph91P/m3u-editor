<?php

namespace App\Jobs;

use App\Models\DynamicGroup;
use App\Services\CachedContentDispatchService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queues one dynamic group's auto-cache downloads in the background after
 * SyncDynamicGroups refreshes its membership.
 *
 * Runs on the `default` queue like QueueCachedContentDownloads: the cache
 * workers are tied up by long-running downloads and this job only creates
 * rows and dispatches. Unique per group until processing starts so repeated
 * refreshes don't stack duplicates.
 */
class QueueDynamicGroupCacheDownloads implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 600;

    public function __construct(
        public int $dynamicGroupId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->dynamicGroupId;
    }

    /**
     * Execute the job.
     */
    public function handle(CachedContentDispatchService $service): void
    {
        $group = DynamicGroup::find($this->dynamicGroupId);
        if (! $group) {
            return;
        }

        $counts = $service->dispatchForDynamicGroup($group);

        // No user notification per refresh; Cached Downloads shows the rows.
        Log::info('QueueDynamicGroupCacheDownloads: dispatched', [
            'dynamic_group_id' => $group->id,
            'counts' => $counts,
        ]);
    }
}
