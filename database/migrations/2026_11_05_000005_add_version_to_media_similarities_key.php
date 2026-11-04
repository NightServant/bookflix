<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The model version becomes part of the primary key. With the old key a candidate version's write replaced the active version's pairs
     * before evaluation promoted it; now every version keeps its own rows, readers join `model_versions.status = 'active'`, and promotion
     * (not the write) decides which rows are served. `media_similarities_top_idx` (media_id, method, score desc) still serves the reads.
     */
    public function up(): void
    {
        DB::statement('alter table media_similarities drop constraint media_similarities_pkey');
        DB::statement('alter table media_similarities add primary key (media_id, method, model_version_id, similar_media_id)');
    }

    /**
     * Reverse the migrations.
     *
     * Keeps the newest version's row for a pair, which is what the old key's upsert left behind.
     */
    public function down(): void
    {
        DB::statement('delete from media_similarities a using media_similarities b where a.media_id = b.media_id and a.method = b.method and a.similar_media_id = b.similar_media_id and a.model_version_id < b.model_version_id');
        DB::statement('alter table media_similarities drop constraint media_similarities_pkey');
        DB::statement('alter table media_similarities add primary key (media_id, method, similar_media_id)');
    }
};
