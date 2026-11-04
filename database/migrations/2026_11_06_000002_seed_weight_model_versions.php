<?php

use Database\Seeders\ModelVersionSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The recommendations are logged against the active `hybrid-weights` version, and the interaction weights are read from theirs. A deploy runs
     * migrations, not seeders, so without this a fresh production database would log nothing until someone seeded by hand. The seeder only creates
     * a name that has no version yet, so an edited or replaced weight set is never reset.
     */
    public function up(): void
    {
        (new ModelVersionSeeder)->run();
    }

    /**
     * Reverse the migrations. The weight sets are data an admin may have edited, so they stay.
     */
    public function down(): void {}
};
