<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('viewer_favorites', function (Blueprint $table) {
            // Universal cross-reference keys, populated server-side from Channel/Series
            // for vod/series (never trusted from the client), or from the AIOStreams
            // addon's own catalog ids/metadata for aiostreams. Lets a favorite made
            // against one source (e.g. an AIOStreams addon) be recognised as "already
            // favorited" against the same title from a different source (Xtream VOD).
            $table->string('imdb_id', 20)->nullable()->after('aio_item_id');
            $table->string('tmdb_id', 20)->nullable()->after('imdb_id');

            // Denormalised AIOStreams metadata (mirrors the equivalent columns added to
            // viewer_watch_progress) so the favorites list can render without a live
            // re-fetch from the addon — AIOStreams items aren't backed by a local
            // Channel/Series row the way vod/series favorites are.
            $table->unsignedInteger('aio_integration_id')->nullable()->after('tmdb_id');
            $table->string('title', 512)->nullable()->after('aio_integration_id');
            $table->text('thumbnail_url')->nullable()->after('title');
            $table->string('item_type', 20)->nullable()->after('thumbnail_url');

            $table->index(['playlist_viewer_id', 'imdb_id'], 'viewer_favorites_viewer_imdb_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('viewer_favorites', function (Blueprint $table) {
            $table->dropIndex('viewer_favorites_viewer_imdb_index');
            $table->dropColumn([
                'imdb_id',
                'tmdb_id',
                'aio_integration_id',
                'title',
                'thumbnail_url',
                'item_type',
            ]);
        });
    }
};
