<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Models\User;
use App\Services\PlaylistService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class AddItemsToCustomPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of Channel or Series records to add
     * @param  array<string, mixed>  $data  Form data: mode, category, new_category
     */
    public function __construct(
        public int $userId,
        public array $itemIds,
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

        foreach (array_chunk($this->itemIds, 1000) as $chunk) {
            DB::table($meta['pivotTable'])->insertOrIgnore(
                array_map(fn (int $id): array => [
                    $meta['pivotForeignKey'] => $this->customPlaylistId,
                    $meta['pivotRelatedKey'] => $id,
                ], $chunk)
            );

            if ($sharedTag) {
                PlaylistService::retagItems($meta, $playlistTagIds, $sharedTag, $chunk);
            } elseif ($mode === 'original') {
                $this->applyOriginalNameTags($meta, $playlistTagIds, $playlist, $chunk);
            }
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

    /**
     * Tag each item in the chunk using its own group/category name (mode 'original'),
     * resolved from the item's own row rather than a batch-wide precomputed value so
     * each item is correctly tagged even when the selection spans many groups.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<int>  $playlistTagIds
     * @param  array<int>  $itemIds
     */
    private function applyOriginalNameTags(array $meta, array $playlistTagIds, CustomPlaylist $playlist, array $itemIds): void
    {
        $namesByItemId = $meta['isSeries']
            ? DB::table('series')
                ->join('categories', 'series.category_id', '=', 'categories.id')
                ->whereIn('series.id', $itemIds)
                ->pluck('categories.name', 'series.id')
            : DB::table('channels')
                ->whereIn('id', $itemIds)
                ->pluck('group', 'id');

        $itemIdsByName = [];
        foreach ($namesByItemId as $itemId => $name) {
            if ($name === null || trim((string) $name) === '') {
                continue;
            }
            $itemIdsByName[$name][] = (int) $itemId;
        }

        foreach ($itemIdsByName as $name => $ids) {
            $tag = Tag::findOrCreate($name, $meta['tagType']);
            $playlist->attachTag($tag);

            PlaylistService::retagItems($meta, $playlistTagIds, $tag, $ids);
        }
    }
}
