<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEpgImportChunk implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public ?int $epgId = null;

    public ?string $reservationOwner = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $jobs,
        public int $batchCount,
        ?int $epgId = null,
        ?string $reservationOwner = null,
    ) {
        $this->epgId = $epgId;
        $this->reservationOwner = $reservationOwner;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->restoreLegacyReservation();

        if ($this->epgId === null
            || ! is_string($this->reservationOwner)
            || ! ProcessEpgImport::refreshReservation($this->epgId, $this->reservationOwner)
        ) {
            throw new \RuntimeException('EPG import reservation ownership was lost before chunk processing.');
        }

        $chunkSize = 10;

        // Process the jobs
        foreach (Job::whereIn('id', $this->jobs)->cursor() as $index => $job) {
            if ($index % $chunkSize === 0) {
                $epg = Epg::find($job->variables['epgId']);
                $epg->update([
                    'progress' => min(99, $epg->progress + (($chunkSize / $this->batchCount) * 100)),
                ]);
            }

            $bulk = $job->payload;

            // Deduplicate the channels
            $bulk = collect($bulk)
                ->unique(fn ($item) => $item['source_id'])
                ->toArray();

            // Upsert the channels
            EpgChannel::upsert($bulk, uniqueBy: ['source_id'], update: [
                // Don't update the following fields...
                // 'name_custom',
                // 'display_name_custom',
                // 'icon_custom',
                // 'epg_id',
                // 'user_id',
                // ...only update the following fields
                'lang',
                'name',         // may change (e.g. PPV channels rename between syncs)
                'display_name',
                'icon',
                'channel_id',
                'import_batch_no',
                'additional_display_names',
            ]);
        }

        if ($this->epgId !== null
            && is_string($this->reservationOwner)
            && ! ProcessEpgImport::refreshReservation($this->epgId, $this->reservationOwner)
        ) {
            throw new \RuntimeException('EPG import reservation ownership was lost after chunk processing.');
        }
    }

    private function restoreLegacyReservation(): void
    {
        if ($this->epgId !== null || $this->reservationOwner !== null) {
            return;
        }

        $jobs = Job::query()->whereIn('id', $this->jobs)->get(['batch_no', 'variables']);
        $epgIds = $jobs
            ->map(fn (Job $job): mixed => $job->variables['epgId'] ?? null)
            ->filter(fn (mixed $epgId): bool => is_numeric($epgId))
            ->map(fn (mixed $epgId): int => (int) $epgId)
            ->unique()
            ->values();
        $batchNumbers = $jobs
            ->pluck('batch_no')
            ->filter(fn (mixed $batchNo): bool => is_string($batchNo) && $batchNo !== '')
            ->unique()
            ->values();

        if ($jobs->isEmpty() || $epgIds->count() !== 1 || $batchNumbers->count() !== 1) {
            throw new \RuntimeException('Legacy EPG import chunk reservation context is invalid.');
        }

        $this->epgId = $epgIds->sole();
        $this->reservationOwner = ProcessEpgImport::acquireCompatibilityReservation(
            $this->epgId,
            $batchNumbers->sole(),
        );

        if (! is_string($this->reservationOwner)) {
            throw new \RuntimeException('Legacy EPG import chunk reservation could not be acquired.');
        }
    }
}
