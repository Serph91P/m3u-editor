<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use Carbon\CarbonInterval;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RefreshPlaylist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-playlist {playlist?} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh playlist in batch (or specific playlist when ID provided)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $playlistId = $this->argument('playlist');
        if ($playlistId) {
            $force = $this->argument('force') ?? false;
            $this->info("Refreshing playlist with ID: {$playlistId}");
            $playlist = Playlist::findOrFail($playlistId);
            dispatch(new ProcessM3uImport($playlist, (bool)$force));
            $this->info('Dispatched playlist for refresh');
        } else {
            $this->info('Refreshing all playlists');
            // Get all playlists that are not currently processing
            $playlists = Playlist::query()->where([
                ['status', '!=', Status::Processing],
                ['auto_sync', '=', true],
            ])->whereNotNull('synced');

            $totalPlaylists = $playlists->count();
            if ($totalPlaylists === 0) {
                $this->info('No playlists available for refresh');
                return;
            }

            $count = 0;
            $playlists->get()->each(function (Playlist $playlist) use (&$count) {
                $cronExpression = new CronExpression($playlist->sync_interval);

                // Check if sync is due (with a 1-minute buffer)
                $lastRun = $playlist->synced ?? now()->subYears(1);
                $nextDue = $cronExpression->getNextRunDate($lastRun->toDateTimeImmutable());

                if (now() >= $nextDue) {
                    $count++;
                    dispatch(new ProcessM3uImport($playlist));
                }
            });
            $this->info('Dispatched ' . $count . ' playlists for refresh');
        }
        return;
    }
}
