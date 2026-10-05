<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links cached files to the dynamic groups that auto-cached them.
     * `dropped_at` is set when the item leaves the group's cache scope;
     * retention releases the link once the rule's keep days have passed.
     */
    public function up(): void
    {
        Schema::create('cached_content_file_dynamic_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cached_content_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dynamic_group_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->unique(['cached_content_file_id', 'dynamic_group_id']);
            $table->index('dynamic_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cached_content_file_dynamic_groups');
    }
};
