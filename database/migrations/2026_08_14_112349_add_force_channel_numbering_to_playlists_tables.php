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
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('force_channel_numbering')->after('channel_start')->default(false);
        });

        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('force_channel_numbering')->after('channel_start')->default(false);
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('force_channel_numbering')->after('channel_start')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('force_channel_numbering');
        });

        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('force_channel_numbering');
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('force_channel_numbering');
        });
    }
};
