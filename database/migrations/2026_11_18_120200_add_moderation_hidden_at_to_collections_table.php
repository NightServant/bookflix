<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Set when a moderator hides a collection (P10-R10); while set the owner can't make it public again.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->timestamp('moderation_hidden_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('moderation_hidden_at');
        });
    }
};
