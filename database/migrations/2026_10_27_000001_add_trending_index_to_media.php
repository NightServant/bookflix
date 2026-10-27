<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Serves the trending order (most rated, then newest) that Home and the hero sort by, over the stories a reader can see.
     */
    public function up(): void
    {
        DB::statement('create index media_trending_idx on media (ratings_count desc, published_on desc nulls last, id desc) where deleted_at is null and merged_into_id is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index if exists media_trending_idx');
    }
};
