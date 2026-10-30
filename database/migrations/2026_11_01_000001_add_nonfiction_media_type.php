<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Nonfiction (Atomic Habits, Sapiens, memoirs) was filed as novels because no type fit it. A data step, so a database seeded earlier gets the type
     * without a re-seed; the classifier then files nonfiction under it on the next sync or `bookflix:reclassify`.
     */
    public function up(): void
    {
        DB::table('media_types')->insertOrIgnore([
            'slug' => 'nonfiction',
            'name' => 'Nonfiction',
            'family' => 'prose',
            'reading_mode' => 'reflowable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Stories filed under the type keep it: removing it would leave them without one.
     */
    public function down(): void {}
};
