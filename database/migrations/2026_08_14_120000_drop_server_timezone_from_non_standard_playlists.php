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
        // Custom/Merged/Alias playlists can be composed of channels from several different
        // source playlists, so a single provider timezone doesn't make sense for them.
        // Timeshift resolution now uses the channel's own originating playlist timezone instead.
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('server_timezone');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('server_timezone');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('server_timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->string('server_timezone')
                ->nullable()
                ->after('enable_proxy');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->string('server_timezone')
                ->nullable()
                ->after('enable_proxy');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->string('server_timezone')->nullable();
        });
    }
};
