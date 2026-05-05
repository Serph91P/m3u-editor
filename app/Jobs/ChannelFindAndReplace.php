<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\User;
use App\Services\StreamProfileRuleEvaluator;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ChannelFindAndReplace implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  array<int, array<string, mixed>>  $conditions  Optional probe-data
     *         conditions in the same shape as StreamProfile rule conditions
     *         ({field, op, value}). When non-empty, rows whose
     *         channels.stream_stats does not satisfy them are skipped.
     */
    public function __construct(
        public int $user_id, // The ID of the user who owns the playlist
        public bool $use_regex,
        public string $column,
        public string $find_replace,
        public string $replace_with,
        public ?Collection $channels = null,
        public ?bool $all_playlists = false,
        public ?int $playlist_id = null,
        public bool $silent = false,
        public ?bool $is_vod = null,
        public array $conditions = [],
        public string $conditions_match_mode = 'all',
        public bool $require_probe_data = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Clock the time
        $start = now();

        // Need to treat some columns differently
        switch ($this->column) {
            case 'logo':
                // Resetting the logo column, `logo_internal` is the default, `logo` is the override
                $customColumn = 'logo';
                $this->column = 'logo_internal'; // Use the internal logo column for find/replace
                break;
            case 'info->description':
            case 'info->genre':
                // These are JSON columns, so we'll be replacing the content directly
                $customColumn = $this->column;
                break;
            default:
                // Most will use the same name appended with `_custom`
                // e.g. `name_custom` for `name`
                // or `title_custom` for `title`
                $customColumn = $this->column.'_custom';
        }
        $updated = 0;

        // Process channels in chunks for better performance
        if (! $this->channels) {
            // Use chunking to process large datasets efficiently
            Channel::query()
                ->where('user_id', $this->user_id)
                ->when(! $this->all_playlists && $this->playlist_id, fn ($query) => $query->where('playlist_id', $this->playlist_id))
                ->when($this->is_vod !== null, fn ($query) => $query->where('is_vod', $this->is_vod))
                ->chunkById(1000, function ($channels) use ($customColumn, &$updated) {
                    $updated += $this->processChannelChunk($channels, $customColumn);
                });
        } else {
            // Process the provided collection in chunks
            $this->channels
                ->chunk(1000)
                ->each(function ($chunk) use ($customColumn, &$updated) {
                    $updated += $this->processChannelChunk($chunk, $customColumn);
                });
        }

        // Notify the user we're done!
        $completedIn = $start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);
        $user = User::find($this->user_id);

        // Send notification
        if (! $this->silent) {
            Notification::make()
                ->success()
                ->title('Find & Replace completed')
                ->body("Channel find & replace has completed successfully. {$updated} channels updated.")
                ->broadcast($user);
            Notification::make()
                ->success()
                ->title('Find & Replace completed')
                ->body("Channel find & replace has completed successfully. Operation completed in {$completedInRounded} seconds and updated {$updated} channels.")
                ->sendToDatabase($user);
        }
    }

    /**
     * Process a chunk of channels and perform find/replace operations
     */
    private function processChannelChunk($channels, string $customColumn): int
    {
        $updatesMap = [];
        $find = $this->find_replace;
        $replace = $this->replace_with;
        $hasConditions = ! empty($this->conditions);
        $evaluator = $hasConditions ? app(StreamProfileRuleEvaluator::class) : null;

        foreach ($channels as $channel) {
            // Probe-data gate: when conditions are configured, the channel
            // must satisfy them before the find/replace is applied. Channels
            // without probe data can't satisfy any condition so they are
            // always skipped — `require_probe_data` only affects whether a
            // future improvement might surface a warning to the user.
            if ($hasConditions) {
                $stats = $channel->stream_stats ?? null;
                if (empty($stats)) {
                    continue;
                }
                if (! $evaluator->matches($this->conditions, $stats, $this->conditions_match_mode)) {
                    continue;
                }
            }

            $newValue = null;

            // Check if this is a JSON column
            if (str_starts_with($this->column, 'info->')) {
                $jsonKey = str_replace('info->', '', $this->column);

                // Get the value we're modifying
                $valueToModify = $channel->info[$jsonKey === 'description' ? 'description' : 'genre'] ?? null;
            } else {
                $providerValue = $channel->{$this->column};
                $customValue = $channel->{$customColumn};

                // Get the value we're modifying
                $valueToModify = $customValue ?? $providerValue;
            }

            // Check if the value to modify is empty
            if (empty($valueToModify)) {
                continue;
            }

            // Determine the value to replace
            if ($this->use_regex) {
                // Escape existing delimiters in user input
                $delimiter = '/';
                $pattern = str_replace($delimiter, '\\'.$delimiter, $find);
                $finalPattern = $delimiter.$pattern.$delimiter.'ui';

                // Check if the find string is in the value to modify
                if (! preg_match($finalPattern, $valueToModify)) {
                    continue;
                }

                // Perform a regex replacement
                $newValue = preg_replace($finalPattern, $replace, $valueToModify);
            } else {
                // Check if the find string is in the value to modify
                if (! stristr($valueToModify, $find)) {
                    continue;
                }

                // Perform a case-insensitive replacement
                $newValue = str_ireplace($find, $replace, $valueToModify);
            }

            if ($newValue && $newValue !== $valueToModify) {
                $updatesMap[$channel->id] = $newValue;
            }
        }

        if (! empty($updatesMap)) {
            return $this->performBatchUpdate($updatesMap, $customColumn);
        }

        return 0;
    }

    /**
     * Perform batch update with database-specific JSON handling
     */
    private function performBatchUpdate(array $updatesMap, string $customColumn): int
    {
        $ids = array_keys($updatesMap);

        if (str_starts_with($this->column, 'info->')) {
            $jsonKey = str_replace('info->', '', $this->column);
            $driver = DB::connection()->getDriverName();

            if ($driver === 'sqlite') {
                // SQLite JSON update using json_set
                $cases = [];
                foreach ($updatesMap as $id => $value) {
                    $cases[] = "WHEN {$id} THEN json_set(COALESCE(info, '{}'), '$.{$jsonKey}', ".DB::connection()->getPdo()->quote($value).')';
                }
                $caseStatement = implode(' ', $cases);

                DB::statement("
                    UPDATE channels 
                    SET info = CASE id {$caseStatement} END,
                        updated_at = ?
                    WHERE id IN (".implode(',', $ids).')
                ', [now()]);
            } elseif ($driver === 'pgsql') {
                // PostgreSQL JSON update using jsonb_set
                $cases = [];
                foreach ($updatesMap as $id => $value) {
                    $cases[] = "WHEN {$id} THEN jsonb_set(COALESCE(info, '{}'), '{".$jsonKey."}', ".DB::connection()->getPdo()->quote(json_encode($value)).')';
                }
                $caseStatement = implode(' ', $cases);

                DB::statement("
                    UPDATE channels 
                    SET info = CASE id {$caseStatement} END,
                        updated_at = ?
                    WHERE id IN (".implode(',', $ids).')
                ', [now()]);
            } else {
                // MySQL/MariaDB JSON update using JSON_SET
                $cases = [];
                foreach ($updatesMap as $id => $value) {
                    $cases[] = "WHEN {$id} THEN JSON_SET(COALESCE(info, '{}'), '$.{$jsonKey}', ".DB::connection()->getPdo()->quote($value).')';
                }
                $caseStatement = implode(' ', $cases);

                DB::statement("
                    UPDATE channels 
                    SET info = CASE id {$caseStatement} END,
                        updated_at = ?
                    WHERE id IN (".implode(',', $ids).')
                ', [now()]);
            }
        } else {
            // Regular column update
            $cases = [];
            foreach ($updatesMap as $id => $value) {
                $cases[] = "WHEN {$id} THEN ".DB::connection()->getPdo()->quote($value);
            }
            $caseStatement = implode(' ', $cases);

            DB::statement("
                UPDATE channels 
                SET {$customColumn} = CASE id {$caseStatement} END,
                    updated_at = ?
                WHERE id IN (".implode(',', $ids).')
            ', [now()]);
        }

        return count($updatesMap);
    }
}
