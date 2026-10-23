<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create extension if not exists unaccent');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop extension if exists unaccent');
        DB::statement('drop extension if exists pg_trgm');
        DB::statement('drop extension if exists vector');
    }
};
