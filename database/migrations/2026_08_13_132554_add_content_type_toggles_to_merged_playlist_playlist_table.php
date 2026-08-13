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
            // Per-source content-type toggles: which content types this attached
            // playlist contributes to the merge. Default true on all so existing
            // attachments keep pulling everything they do today.
            $table->boolean('include_live')->default(true)->after('playlist_id');
            $table->boolean('include_vod')->default(true)->after('include_live');
            $table->boolean('include_series')->default(true)->after('include_vod');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_playlist_playlist', function (Blueprint $table) {
            $table->dropColumn(['include_live', 'include_vod', 'include_series']);
        });
    }
};
