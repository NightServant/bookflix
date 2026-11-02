<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MangaDex refuses its images to other sites (a 403, or a redirect to a placeholder), so its covers are served from our own copy. A data
     * step, so a database seeded earlier gets the policy without a re-seed.
     */
    public function up(): void
    {
        DB::table('external_sources')->where('key', 'mangadex')->update(['cover_policy' => 'cache', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('external_sources')->where('key', 'mangadex')->update(['cover_policy' => 'hotlink', 'updated_at' => now()]);
    }
};
