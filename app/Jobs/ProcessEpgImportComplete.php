<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\EpgChannel;
use App\Models\Job;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessEpgImportComplete implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    public ?string $reservationOwner = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public int $epgId,
        public string $batchNo,
        public Carbon $start,
        ?string $reservationOwner = null,
    ) {
        $this->reservationOwner = $reservationOwner;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $legacyReservation = $this->reservationOwner === null;
        $this->reservationOwner ??= ProcessEpgImport::acquireCompatibilityReservation(
            $this->epgId,
            $this->batchNo,
        );

        if (! is_string($this->reservationOwner)
            || ! ProcessEpgImport::refreshReservation($this->epgId, $this->reservationOwner)
        ) {
            throw new \RuntimeException('EPG import completion reservation ownership was lost.');
        }

        try {
            $this->handleReservedCompletion();
        } catch (\Throwable $throwable) {
            if ($legacyReservation) {
                ProcessEpgImport::forgetCompatibilityReservation($this->epgId, $this->batchNo);

                if (! $this->reservationTransferred) {
                    ProcessEpgImport::releaseReservation($this->epgId, $this->reservationOwner);
                }
            }

            throw $throwable;
        }

        if ($legacyReservation) {
            ProcessEpgImport::forgetCompatibilityReservation($this->epgId, $this->batchNo);
        }

        if (! $this->reservationTransferred) {
            ProcessEpgImport::releaseReservation($this->epgId, $this->reservationOwner);
        }
    }

    private bool $reservationTransferred = false;

    private function handleReservedCompletion(): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        $user = User::find($this->userId);
        $epg = $user->epgs()->find($this->epgId);

        // Send notification
        Notification::make()
            ->success()
            ->title('EPG Synced')
            ->body("\"{$epg->name}\" has been synced successfully.")
            ->broadcast($epg->user);
        Notification::make()
            ->success()
            ->title('EPG Synced')
            ->body("\"{$epg->name}\" has been synced successfully. Import completed in {$completedInRounded} seconds.")
            ->sendToDatabase($epg->user);

        // Clear out invalid channels (if any)
        EpgChannel::where([
            ['epg_id', $epg->id],
            ['import_batch_no', '!=', $this->batchNo],
        ])->delete();

        // Clear out the jobs
        Job::where('batch_no', $this->batchNo)->delete();

        // Before marking complete, check if we need to auto-resync due to 0 channels.
        // This must happen before mapping is triggered.
        if ($epg->channel_count === 0 && $epg->auto_resync_on_failure && $epg->resync_attempt < $epg->auto_resync_retries) {
            $newAttempt = $epg->resync_attempt + 1;
            $delaySeconds = $newAttempt * 60;

            Log::info("ProcessEpgImportComplete: channel count is 0, scheduling resync attempt {$newAttempt}/{$epg->auto_resync_retries} for EPG {$epg->id} in {$delaySeconds}s");

            Notification::make()
                ->warning()
                ->title('EPG resync queued')
                ->body("Channel count is 0. Retry {$newAttempt} of {$epg->auto_resync_retries} scheduled for \"{$epg->name}\" (delay: {$delaySeconds}s).")
                ->sendToDatabase($epg->user);

            if (! ProcessEpgImport::dispatchContinuation(
                $epg,
                $this->reservationOwner,
                force: true,
                delay: now()->addSeconds($delaySeconds),
            )) {
                throw new \RuntimeException('EPG import retry reservation ownership was lost.');
            }
            $this->reservationTransferred = true;

            $epg->update([
                'status' => Status::Pending,
                'progress' => 0,
                'processing' => false,
                'errors' => 'Channel count is 0 after sync, retrying...',
                'resync_attempt' => $newAttempt,
            ]);

            return;
        }

        // Update the epg
        $epg->update([
            'status' => Status::Completed,
            'synced' => now(),
            'errors' => null,
            'sync_time' => $completedIn,
            'progress' => 100,
            'processing' => false,
            'resync_attempt' => 0,
        ]);

        // Check if there are any sync jobs that should be re-run
        $epg->epgMaps()->where('recurring', true)->get()->each(function ($map) {
            dispatch(new MapPlaylistChannelsToEpg(
                epg: $map->epg_id,
                playlist: $map->playlist_id,
                epgMapId: $map->id,
            ));
        });

        // Fire the epg synced event
        event(new SyncCompleted($epg));
    }
}
