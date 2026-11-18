<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The handle is the public identifier in profile URLs (P10-R2), stored lowercase so a plain unique index is case-insensitive.
     * `moderation_hidden_at` is set when a moderator hides the profile (P10-R10); while it is set the owner can't make it public.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('handle', 32)->nullable()->unique();
            $table->timestamp('moderation_hidden_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropUnique(['handle']);
            $table->dropColumn(['handle', 'moderation_hidden_at']);
        });
    }
};
