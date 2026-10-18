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
        Schema::table('merged_playlist_playlist', function (Blueprint $table) {
            // Per-source opt-in: for a media-server source, only contribute items that no
            // other source in the merge already provides via "Prefer media server sources".
            // Default false so existing merges keep pulling everything they do today.
            $table->boolean('only_unmatched')->default(false)->after('include_series');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_playlist_playlist', function (Blueprint $table) {
            $table->dropColumn('only_unmatched');
        });
    }
};
