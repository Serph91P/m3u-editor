<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emby_library_mappings', function (Blueprint $table): void {
            $table->timestamp('managed_library_creation_confirmed_at')->nullable()->after('is_managed');
            $table->timestamp('library_options_applied_at')->nullable()->after('managed_library_creation_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('emby_library_mappings', function (Blueprint $table): void {
            $table->dropColumn(['library_options_applied_at', 'managed_library_creation_confirmed_at']);
        });
    }
};
