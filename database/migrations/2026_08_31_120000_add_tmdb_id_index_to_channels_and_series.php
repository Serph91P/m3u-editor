<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Back the DynamicGroup membership lookup with an index.
     *
     * DynamicGroup::itemsMatchingTmdbIds() filters
     * `WHERE playlist_id = ? AND tmdb_id IN (...)` on channels/series for every
     * enabled rule on every SyncDynamicGroups run (pipeline phase + daily
     * cron). Without a (playlist_id, tmdb_id) composite this scans the whole
     * playlist's rows per rule.
     *
     * Note for large deployments: this builds an index on channels/series,
     * which the import and sync job chains write to continuously. On Postgres
     * a plain CREATE INDEX takes a SHARE lock for the build; if those tables
     * are huge, pre-build the equivalent index with CREATE INDEX CONCURRENTLY
     * before deploying so the migration is a no-op.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->index(['playlist_id', 'tmdb_id'], 'channels_playlist_tmdb_idx');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->index(['playlist_id', 'tmdb_id'], 'series_playlist_tmdb_idx');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_playlist_tmdb_idx');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropIndex('series_playlist_tmdb_idx');
        });
    }
};
