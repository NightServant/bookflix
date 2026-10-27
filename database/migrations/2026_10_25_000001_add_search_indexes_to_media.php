<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The full-text expression must stay identical to the one Scout's database engine emits for `search_text`
     * (`to_tsvector('simple', ...)`), or Postgres will not use the index; a test compares the two.
     * `unaccent` is only stable, so indexes go through an immutable wrapper.
     */
    public function up(): void
    {
        DB::statement("create or replace function immutable_unaccent(text) returns text language sql immutable parallel safe as \$\$ select public.unaccent('public.unaccent', \$1) \$\$");

        DB::statement("create index media_search_fts on media using gin (to_tsvector('simple', search_text))");
        DB::statement('create index media_title_trgm_unaccent on media using gin (immutable_unaccent(lower(title)) gin_trgm_ops)');
        DB::statement('create index media_alt_titles_trgm on media using gin (immutable_unaccent(lower(alternative_titles::text)) gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index if exists media_alt_titles_trgm');
        DB::statement('drop index if exists media_title_trgm_unaccent');
        DB::statement('drop index if exists media_search_fts');
        DB::statement('drop function if exists immutable_unaccent(text)');
    }
};
