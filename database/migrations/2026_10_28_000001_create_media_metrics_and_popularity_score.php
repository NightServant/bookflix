<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `create index concurrently` cannot run inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     *
     * `media_metrics` holds each source's latest popularity signals per media; `media.popularity_score` is their blended
     * percentile rank. The trending index moves to lead with the score, built concurrently so provider syncs are not blocked.
     */
    public function up(): void
    {
        Schema::create('media_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('external_source_id')->constrained('external_sources');
            $table->string('metric');
            $table->double('value');
            $table->timestampTz('observed_at');

            $table->unique(['media_id', 'external_source_id', 'metric']);
            $table->index(['external_source_id', 'metric']);
        });

        Schema::table('media', function (Blueprint $table) {
            $table->double('popularity_score')->default(0);
        });

        DB::statement('create index concurrently if not exists media_trending_score_idx on media (popularity_score desc, ratings_count desc, published_on desc nulls last, id desc) where deleted_at is null and merged_into_id is null');
        DB::statement('drop index concurrently if exists media_trending_idx');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('create index concurrently if not exists media_trending_idx on media (ratings_count desc, published_on desc nulls last, id desc) where deleted_at is null and merged_into_id is null');
        DB::statement('drop index concurrently if exists media_trending_score_idx');

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('popularity_score');
        });

        Schema::dropIfExists('media_metrics');
    }
};
