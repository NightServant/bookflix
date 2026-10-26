<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per settled search (A6). `user_id` stays null for a reader who opted out of personalization, and goes null
     * when the account is deleted; `session_hash` is a keyed hash, never the session id.
     */
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->char('session_hash', 64)->index();
            $table->string('query');
            $table->string('normalized');
            $table->jsonb('filters')->default('{}');
            $table->jsonb('intent')->nullable();
            $table->unsignedInteger('results_count');
            $table->foreignId('previous_query_id')->nullable()->constrained('search_queries')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
