<?php

namespace App\Jobs;

use App\Events\SyncCompleted;
use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Rules\ValidRegexPattern;
use App\Services\PlaylistService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class AutoSyncGroupsToCustomPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $groupIds  IDs of Group or Category records to process
     * @param  array<string, mixed>  $data  Form data: mode, category, new_category
     * @param  string  $syncMode  'full_sync' removes channels no longer in source groups; 'add_only' never removes
     * @param  array<int>  $channelIds  Optional channel IDs to restrict the sync to (live/VOD rules only)
     * @param  array<string>  $channelNamePatterns  Optional delimiter-less regex patterns matched against channel titles/names
     *
     * When either channel filter is set, only channels that match at least one of them
     * (picked ID or name pattern) are synced out of the selected groups.
     */
    public function __construct(
        public int $userId,
        public int $playlistId,
        public array $groupIds,
        public int $customPlaylistId,
        public array $data,
        public string $type = 'channel',
        public string $syncMode = 'full_sync',
        public array $channelIds = [],
        public array $channelNamePatterns = [],
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);
        $user = User::findOrFail($this->userId);

        $meta = PlaylistService::resolveCustomPlaylistRelationMeta($playlist, $this->type);
        $isSeries = $meta['isSeries'];
        $sharedTag = PlaylistService::resolveSharedTagForMode($playlist, $this->data, $meta['tagType']);
        $mode = $this->data['mode'] ?? 'original';

        $playlistTagIds = $playlist->{$meta['tagFunction']}()->pluck('tags.id')->all();

        $compiledPatterns = $this->compiledChannelPatterns();
        $hasChannelFilter = ! $isSeries && ($this->channelIds !== [] || $compiledPatterns !== []);
        $pickedChannelIds = array_flip(array_map('intval', $this->channelIds));

        // Resolved once here for reuse in the full-sync cleanup below.
        $pivotTable = $meta['pivotTable'];
        $pivotForeignKey = $meta['pivotForeignKey'];
        $pivotRelatedKey = $meta['pivotRelatedKey'];

        foreach ($this->groupIds as $groupId) {
            $group = $isSeries
                ? Category::find($groupId)
                : Group::find($groupId);

            if (! $group) {
                continue;
            }

            // For 'original' mode, derive the tag name from the group/category model
            $tag = $sharedTag;
            if ($mode === 'original') {
                $originalName = $group->name ?? $group->name_internal ?? null;
                if ($originalName === null || trim((string) $originalName) === '') {
                    continue;
                }
                $tag = Tag::findOrCreate($originalName, $meta['tagType']);
                $playlist->attachTag($tag);
            }

            // Chunk through the group's items to avoid memory exhaustion on large groups.
            // Use insertOrIgnore instead of syncWithoutDetaching so we never load the
            // entire pivot table into PHP memory on each iteration, and existing pivot
            // values (channel_number, sort) are preserved by the conflict-ignore path.
            // insertOrIgnore is also why auto_synced_playlist_id only marks a NEW row as
            // rule-managed: it never touches a row that already exists, so a channel a
            // user separately added manually (or that an earlier run already tracked)
            // keeps its existing provenance instead of being silently reclaimed here.
            $group->{$meta['relation']}()->chunkById(1000, function ($items) use ($meta, $playlistTagIds, $tag, $hasChannelFilter, $pickedChannelIds, $compiledPatterns): void {
                if ($hasChannelFilter) {
                    $items = $items->filter(fn ($item): bool => $this->matchesChannelFilter(
                        (int) $item->id,
                        [$item->title_custom ?? $item->title, $item->name_custom ?? $item->name],
                        $pickedChannelIds,
                        $compiledPatterns,
                    ));
                }

                $ids = $items->pluck('id')->all();

                if (empty($ids)) {
                    return;
                }

                DB::table($meta['pivotTable'])->insertOrIgnore(
                    array_map(fn (int $id): array => [
                        $meta['pivotForeignKey'] => $this->customPlaylistId,
                        $meta['pivotRelatedKey'] => $id,
                        'auto_synced_playlist_id' => $this->playlistId,
                    ], $ids)
                );

                if ($tag) {
                    PlaylistService::retagItems($meta, $playlistTagIds, $tag, $ids);
                }
            });
        }

        // Full sync: delete pivot rows this rule previously added (tracked via
        // auto_synced_playlist_id, set above) whose item is no longer in one of this
        // rule's active source groups. Using provenance instead of the item's current
        // group means a manually attached item is never swept up here regardless of
        // its group, and an item this rule added is still removed after it moves to a
        // different, active, non-selected group - group membership alone can't tell
        // those two cases apart (issue #1695).
        // Done entirely in the database — no PHP-level ID collection or diff needed.
        if ($this->syncMode === 'full_sync') {
            $itemTable = $isSeries ? 'series' : 'channels';
            $groupColumn = $isSeries ? 'category_id' : 'group_id';

            // Restrict "currently active" to groups that still exist (not soft-deleted).
            // Channels belonging to soft-deleted groups still carry the old group_id value,
            // so without this filter they would be wrongly treated as "still present",
            // showing as "Uncategorized" in the playlist instead of being removed.
            $activeGroupIds = $isSeries
                ? Category::whereIn('id', $this->groupIds)->pluck('id')->all()
                : Group::whereIn('id', $this->groupIds)->pluck('id')->all();

            if ($hasChannelFilter) {
                $this->removeStaleFilteredChannels($pivotTable, $pivotForeignKey, $pivotRelatedKey, $activeGroupIds, $pickedChannelIds, $compiledPatterns);
            } else {
                DB::table($pivotTable)
                    ->where($pivotForeignKey, $this->customPlaylistId)
                    ->where('auto_synced_playlist_id', $this->playlistId)
                    ->whereNotIn($pivotRelatedKey, function ($query) use ($itemTable, $groupColumn, $activeGroupIds): void {
                        // Items currently present in active (non-deleted) source groups only.
                        // Using $activeGroupIds (not $this->groupIds) ensures channels whose
                        // group was soft-deleted are not falsely treated as "still present".
                        $query->select('id')
                            ->from($itemTable)
                            ->whereIn($groupColumn, $activeGroupIds);
                    })
                    ->delete();
            }

            // Detach group tags from this custom playlist that no longer have any channels.
            // This removes "ghost" groups left behind when channels are removed.
            // Checking against actual pivot membership means a tag shared across two source
            // playlists (same group name) is only detached once both playlists' channels are gone.
            $itemMorphClass = $meta['itemModel'];

            $playlist->tagsWithType($meta['tagType'])->each(function (Tag $tag) use ($playlist, $itemMorphClass, $pivotTable, $pivotForeignKey, $pivotRelatedKey): void {
                $stillHasItems = DB::table('taggables')
                    ->where('tag_id', $tag->id)
                    ->where('taggable_type', $itemMorphClass)
                    ->whereIn('taggable_id', function ($q) use ($pivotTable, $pivotForeignKey, $pivotRelatedKey): void {
                        $q->select($pivotRelatedKey)
                            ->from($pivotTable)
                            ->where($pivotForeignKey, $this->customPlaylistId);
                    })
                    ->exists();

                if (! $stillHasItems) {
                    $playlist->detachTag($tag);
                }
            });

            // Prune any group IDs from the source playlist's auto-sync config that are
            // no longer active (soft-deleted). We do this here — after channels and tags
            // are cleaned up — so the dispatch guard never sees empty groups and skips
            // this job before cleanup has a chance to run.
            if (! $isSeries) {
                $inactiveGroupIds = array_values(array_diff($this->groupIds, $activeGroupIds));
                if (! empty($inactiveGroupIds)) {
                    $sourcePlaylist = Playlist::find($this->playlistId);
                    Group::withTrashed()
                        ->whereIn('id', $inactiveGroupIds)
                        ->get(['id', 'type'])
                        ->groupBy('type')
                        ->each(function ($groups, $groupType) use ($sourcePlaylist): void {
                            $ruleType = $groupType === 'vod' ? 'vod_groups' : 'live_groups';
                            $sourcePlaylist?->pruneAutoSyncGroupIds(
                                $groups->pluck('id')->map(fn ($id) => (int) $id)->all(),
                                $ruleType
                            );
                        });
                }
            }
        }

        $notification = Notification::make()
            ->success()
            ->title(__('Auto-sync to custom playlist completed'))
            ->body(__('Groups have been synced to the custom playlist for ":playlist".', ['playlist' => $playlist->name]));

        $notification->broadcast($user)->sendToDatabase($user);

        if ($playlist->hasEnabledProcessingRules()) {
            SyncCompleted::dispatch($playlist, 'custom_playlist');
        }
    }

    /**
     * Delete pivot rows this rule added whose channel no longer sits in an active source
     * group or no longer matches the channel filter. The filter is evaluated in PHP (name
     * patterns), so rows are walked in keyset chunks instead of one subquery.
     *
     * @param  array<int>  $activeGroupIds
     * @param  array<int, int>  $pickedChannelIds  Picked channel IDs as array keys
     * @param  array<string>  $compiledPatterns
     */
    private function removeStaleFilteredChannels(
        string $pivotTable,
        string $pivotForeignKey,
        string $pivotRelatedKey,
        array $activeGroupIds,
        array $pickedChannelIds,
        array $compiledPatterns,
    ): void {
        $activeGroupLookup = array_flip($activeGroupIds);

        DB::table($pivotTable)
            ->where($pivotForeignKey, $this->customPlaylistId)
            ->where('auto_synced_playlist_id', $this->playlistId)
            ->chunkById(1000, function ($rows) use ($pivotTable, $pivotForeignKey, $pivotRelatedKey, $activeGroupLookup, $pickedChannelIds, $compiledPatterns): void {
                $channelIds = $rows->pluck($pivotRelatedKey)->all();

                $channels = DB::table('channels')
                    ->whereIn('id', $channelIds)
                    ->get(['id', 'group_id', 'title', 'title_custom', 'name', 'name_custom'])
                    ->keyBy('id');

                $staleIds = array_values(array_filter($channelIds, function ($channelId) use ($channels, $activeGroupLookup, $pickedChannelIds, $compiledPatterns): bool {
                    $channel = $channels->get($channelId);

                    if (! $channel || ! isset($activeGroupLookup[$channel->group_id])) {
                        return true;
                    }

                    return ! $this->matchesChannelFilter(
                        (int) $channel->id,
                        [$channel->title_custom ?? $channel->title, $channel->name_custom ?? $channel->name],
                        $pickedChannelIds,
                        $compiledPatterns,
                    );
                }));

                if ($staleIds !== []) {
                    DB::table($pivotTable)
                        ->where($pivotForeignKey, $this->customPlaylistId)
                        ->where('auto_synced_playlist_id', $this->playlistId)
                        ->whereIn($pivotRelatedKey, $staleIds)
                        ->delete();
                }
            }, $pivotRelatedKey);
    }

    /**
     * A channel passes when it was picked by ID or any of its names matches a pattern.
     *
     * @param  array<int, string|null>  $names
     * @param  array<int, int>  $pickedChannelIds  Picked channel IDs as array keys
     * @param  array<string>  $compiledPatterns
     */
    private function matchesChannelFilter(int $channelId, array $names, array $pickedChannelIds, array $compiledPatterns): bool
    {
        if (isset($pickedChannelIds[$channelId])) {
            return true;
        }

        foreach ($names as $name) {
            if ($name === null || $name === '') {
                continue;
            }

            foreach ($compiledPatterns as $pattern) {
                if (@preg_match($pattern, $name) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function compiledChannelPatterns(): array
    {
        return array_values(array_map(
            fn (string $pattern): string => ValidRegexPattern::compile($pattern),
            array_filter($this->channelNamePatterns, fn ($pattern): bool => is_string($pattern) && $pattern !== ''),
        ));
    }
}
