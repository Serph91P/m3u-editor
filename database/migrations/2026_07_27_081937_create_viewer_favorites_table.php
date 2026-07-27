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
        Schema::create('viewer_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_viewer_id')->constrained('playlist_viewers')->cascadeOnDelete();
            $table->enum('content_type', ['live', 'vod', 'series', 'aiostreams']);
            $table->unsignedBigInteger('stream_id')->nullable(); // Channel.id (live/vod) or Series.id
            $table->string('aio_item_id', 64)->nullable(); // AIOStreams content has no integer stream_id
            $table->timestamp('favorited_at')->useCurrent();
            $table->timestamps();

            // MySQL and Postgres both treat NULL as distinct in unique indexes, so these two
            // indexes only ever constrain the row kind they're relevant to — mirrors the
            // vwp_viewer_aio_item_unique split on viewer_watch_progress.
            $table->unique(['playlist_viewer_id', 'content_type', 'stream_id'], 'viewer_favorites_stream_unique');
            $table->unique(['playlist_viewer_id', 'aio_item_id'], 'viewer_favorites_aio_item_unique');
            $table->index(['playlist_viewer_id', 'content_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('viewer_favorites');
    }
};
