<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackup;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination as SpatieBackupDestination;

class PruneOldBackups implements ShouldQueue
{
    use Queueable;

    // Only try to process the job twice
    public $tries = 2;

    /**
     * Create a new job instance.
     *
     * Runs after a replacement backup has already been created, so the
     * excess/expired backups being pruned are never the only archive
     * available. See CreateBackup, which this job is chained after.
     */
    public function __construct(
        public int $max,
        public int $deleteAfterDays,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->max <= 0 && $this->deleteAfterDays <= 0) {
            return;
        }

        $data = [];
        foreach (FilamentSpatieLaravelBackup::getDisks() as $disk) {
            $data = array_merge($data, FilamentSpatieLaravelBackup::getBackupDestinationData($disk));
        }

        // Order by backup date
        $data = collect($data)->sortByDesc('date');

        // Determine which backups should be deleted for exceeding the max backups count...
        $toDelete = collect();
        if ($this->max > 0 && $data->count() > $this->max) {
            $toDelete = $data->slice($this->max);
        }

        // ...and which should be deleted for being older than the configured retention period
        if ($this->deleteAfterDays > 0) {
            $cutoff = now()->subDays($this->deleteAfterDays);
            $expired = $data->filter(fn ($record) => Carbon::parse($record['date'])->lt($cutoff));
            $toDelete = $toDelete->merge($expired)->unique('path');
        }

        foreach ($toDelete as $record) {
            Log::info("Deleting old backup: {$record['path']}");
            $backup = SpatieBackupDestination::create($record['disk'], config('backup.backup.name'))
                ->backups()
                ->first(function (Backup $backup) use ($record) {
                    return $backup->path() === $record['path'];
                });
            $backup?->delete();
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Pruning old backups failed: {$exception->getMessage()}");
    }
}
