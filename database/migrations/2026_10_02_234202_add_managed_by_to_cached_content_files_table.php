<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which automated feature owns this cached file (CachedContentManagedBy).
     * NULL means the file was cached manually and retention never deletes it.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->string('managed_by')->nullable()->after('failure_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->dropColumn('managed_by');
        });
    }
};
