<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Models\User;
use App\Services\PlaylistService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DetachItemsFromCustomPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of Channel or Series records to detach
     */
    public function __construct(
        public int $userId,
        public array $itemIds,
        public int $customPlaylistId,
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
        $playlistTagIds = $playlist->{$meta['tagFunction']}()->pluck('tags.id')->all();

        foreach (array_chunk($this->itemIds, 2000) as $chunk) {
            DB::table('taggables')
                ->where('taggable_type', $meta['itemModel'])
                ->whereIn('taggable_id', $chunk)
                ->whereIn('tag_id', $playlistTagIds)
                ->delete();

            DB::table($meta['pivotTable'])
                ->where($meta['pivotForeignKey'], $this->customPlaylistId)
                ->whereIn($meta['pivotRelatedKey'], $chunk)
                ->delete();
        }

        Notification::make()
            ->success()
            ->title(__('Items detached from custom playlist'))
            ->body(__('The selected items have been detached from the custom playlist.'))
            ->broadcast($user);

        Notification::make()
            ->success()
            ->title(__('Items detached from custom playlist'))
            ->body(__('The selected items have been detached from the custom playlist.'))
            ->sendToDatabase($user);
    }
}
