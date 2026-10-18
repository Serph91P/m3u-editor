<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow negative padding on recording rules (start late / finish early).
     */
    public function up(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            $table->integer('start_early_seconds')->nullable()->change();
            $table->integer('end_late_seconds')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Postgres has no unsigned types, so the smallint ceiling (32767) is the safe cap.
        foreach (['start_early_seconds', 'end_late_seconds'] as $column) {
            DB::table('dvr_recording_rules')->where($column, '<', 0)->update([$column => 0]);
            DB::table('dvr_recording_rules')->where($column, '>', 32767)->update([$column => 32767]);
        }

        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('start_early_seconds')->nullable()->change();
            $table->unsignedSmallInteger('end_late_seconds')->nullable()->change();
        });
    }
};
