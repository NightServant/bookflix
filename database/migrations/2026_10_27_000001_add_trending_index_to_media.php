<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `create index concurrently` cannot run inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     *
     * Serves the trending order (most rated, then newest) that Home and the hero sort by, over the stories a reader can see.
     * Built concurrently, so it does not block the provider syncs writing to `media` while it builds on a large catalog.
     */
    public function up(): void
    {
        DB::statement('create index concurrently if not exists media_trending_idx on media (ratings_count desc, published_on desc nulls last, id desc) where deleted_at is null and merged_into_id is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index concurrently if exists media_trending_idx');
    }
};
