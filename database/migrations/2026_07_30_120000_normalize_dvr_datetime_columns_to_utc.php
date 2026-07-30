<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Normalize dvr_recordings/dvr_recording_rules datetime columns to an
 * explicit UTC instant, matching App\Casts\UtcDateTime (now applied to these
 * columns on both models).
 *
 * Why: these columns previously stored a bare app-timezone wall-clock string
 * with no timezone marker ("13:46:00" meaning 13:46 in app TZ). Comparing
 * that against `now()` — or any other datetime originating in a different
 * timezone — is only safe by accident (when both happen to be formatted in
 * the same timezone). App\Casts\UtcDateTime fixes this going forward by
 * always normalizing to UTC on write; this migration normalizes the
 * already-stored data to match, so old and new rows are comparable.
 *
 * - Postgres: converts the column to `timestamptz` via `... AT TIME ZONE
 *   <app tz>`, which both reinterprets the existing bare values as app-TZ
 *   wall-clock and gives Postgres a type that tracks the offset itself from
 *   here on.
 * - SQLite: has no timezone-aware column type — every datetime is TEXT. Rows
 *   are rewritten in place, parsing the existing value as app-TZ wall-clock
 *   and re-storing in App\Casts\UtcDateTime::STORAGE_FORMAT so it text-sorts
 *   and text-matches correctly against everything the app writes going
 *   forward (SQLite has no engine-level timezone comparison — string equality
 *   is all it has).
 *
 * Known limitation: this backfill assumes every existing row was written
 * under the *current* config('app.timezone'). If APP_TIMEZONE was changed at
 * some point in this install's history, rows written under the old timezone
 * will be misinterpreted (converted using the wrong offset), reproducing the
 * original bug for that subset of legacy data — there is no way to recover
 * which timezone was active when a given pre-fix row was written, since that
 * information was never stored. App\Casts\UtcDateTime itself has no such
 * exposure: it always normalizes through UTC on write, independent of
 * whatever APP_TIMEZONE is set to at read time, so this limitation is
 * confined to this one-time backfill of pre-fix data, not an ongoing risk.
 * Run this migration promptly after deploying the cast change, before any
 * further APP_TIMEZONE changes, to avoid it.
 */
return new class extends Migration
{
    private const TABLE_COLUMNS = [
        'dvr_recordings' => [
            'scheduled_start', 'scheduled_end', 'actual_start', 'actual_end',
            'programme_start', 'programme_end',
        ],
        'dvr_recording_rules' => ['manual_start', 'manual_end'],
    ];

    public function up(): void
    {
        $appTz = config('app.timezone', 'UTC');

        match (DB::getDriverName()) {
            'pgsql' => $this->convertPostgresColumns($appTz, toTz: true),
            'sqlite' => $this->backfillSqliteColumns($appTz, toUtc: true),
            default => null,
        };
    }

    public function down(): void
    {
        $appTz = config('app.timezone', 'UTC');

        match (DB::getDriverName()) {
            'pgsql' => $this->convertPostgresColumns($appTz, toTz: false),
            'sqlite' => $this->backfillSqliteColumns($appTz, toUtc: false),
            default => null,
        };
    }

    private function convertPostgresColumns(string $appTz, bool $toTz): void
    {
        $targetType = $toTz ? 'timestamptz' : 'timestamp';

        foreach (self::TABLE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE %s USING (%s AT TIME ZONE %s)',
                    $table,
                    $column,
                    $targetType,
                    $column,
                    DB::connection()->getPdo()->quote($appTz),
                ));
            }
        }
    }

    /**
     * SQLite stores every datetime as TEXT with no engine-level timezone
     * awareness, so there's no column-type equivalent of the Postgres
     * conversion above — the existing values have to be rewritten directly.
     */
    private function backfillSqliteColumns(string $appTz, bool $toUtc): void
    {
        foreach (self::TABLE_COLUMNS as $table => $columns) {
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, $appTz, $toUtc): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $raw = $row->$column;

                        if ($raw === null) {
                            continue;
                        }

                        $updates[$column] = $toUtc
                            ? Carbon::parse($raw, $appTz)->utc()->format('Y-m-d H:i:sP')
                            : Carbon::parse($raw)->setTimezone($appTz)->format('Y-m-d H:i:s');
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
        }
    }
};
