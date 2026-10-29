<?php

use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A database that already holds catalogue data gets the new vocabulary and its old genre, theme and content warning data moved onto it
     * (see LegacyTaxonomyMigrator). A fresh database has nothing to move, so it is left empty for the seeders and tests to fill.
     */
    public function up(): void
    {
        $hasLegacyData = DB::table('media_genres')->exists()
            || DB::table('media_themes')->exists()
            || DB::table('media')->whereRaw("content_warnings <> '[]'::jsonb")->exists();

        if ($hasLegacyData) {
            (new TaxonomySeeder)->run();
        }
    }

    /**
     * The vocabulary and the moved rows stay: this data step is not undone.
     */
    public function down(): void {}
};
