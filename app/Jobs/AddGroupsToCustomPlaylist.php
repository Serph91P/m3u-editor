<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\User;
use App\Services\PlaylistService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class AddGroupsToCustomPlaylist implements ShouldQueue
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
     */
    public function __construct(
        public int $userId,
        public array $groupIds,
        public int $customPlaylistId,
        public array $data,
        public string $type = 'channel',
    ) {
        $this->onQueue('import');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);
        $user = User::findOrFail($this->userId);

        $meta = PlaylistService::resolveCustomPlaylistRelationMeta($playlist, $this->type);
        $sharedTag = PlaylistService::resolveSharedTagForMode($playlist, $this->data, $meta['tagType']);
        $mode = $this->data['mode'] ?? 'select';

        $playlistTagIds = $playlist->{$meta['tagFunction']}()->pluck('tags.id')->all();

        foreach ($this->groupIds as $groupId) {
            $group = $meta['isSeries']
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

            // Chunk through the group's items to avoid memory exhaustion on large groups
            $group->{$meta['relation']}()->chunkById(1000, function ($items) use ($meta, $playlistTagIds, $tag): void {
                $ids = $items->pluck('id')->all();

                DB::table($meta['pivotTable'])->insertOrIgnore(
                    array_map(fn (int $id): array => [
                        $meta['pivotForeignKey'] => $this->customPlaylistId,
                        $meta['pivotRelatedKey'] => $id,
                    ], $ids)
                );

                if ($tag) {
                    PlaylistService::retagItems($meta, $playlistTagIds, $tag, $ids);
                }
            });
        }

        Notification::make()
            ->success()
            ->title(__('Items added to custom playlist'))
            ->body(__('The selected items have been added to the chosen custom playlist.'))
            ->broadcast($user);

        Notification::make()
            ->success()
            ->title(__('Items added to custom playlist'))
            ->body(__('The selected items have been added to the chosen custom playlist.'))
            ->sendToDatabase($user);
    }
}
